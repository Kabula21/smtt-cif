<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Biometry;

use RuntimeException;

final class ImageInspector
{
    private const MAX_BYTES = 12_582_912; // 12 MiB
    private const MAX_PIXELS = 50_000_000;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png'];

    /**
     * @return array{mime_type:string,size:int,width:int,height:int,dpi_x:?float,dpi_y:?float,dpi_source:?string}
     */
    public function inspectUpload(array $upload): array
    {
        $error = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->uploadErrorMessage($error));
        }

        $path = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('O arquivo enviado não pôde ser lido pelo servidor.');
        }

        $size = filesize($path);
        if (!is_int($size) || $size < 1) {
            throw new \InvalidArgumentException('O arquivo enviado está vazio.');
        }
        if ($size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Cada imagem biométrica deve possuir no máximo 12 MiB.');
        }

        $image = @getimagesize($path);
        if (!is_array($image) || !isset($image[0], $image[1])) {
            throw new \InvalidArgumentException('O arquivo enviado não é uma imagem válida.');
        }

        $mime = isset($image['mime']) ? strtolower((string) $image['mime']) : '';
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($path);
            if (is_string($detected) && $detected !== '') {
                $mime = strtolower($detected);
            }
        }

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new \InvalidArgumentException('Formato não permitido. Utilize imagem JPEG ou PNG.');
        }

        $width = (int) $image[0];
        $height = (int) $image[1];
        if ($width < 1 || $height < 1 || ($width * $height) > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('A imagem possui dimensões inválidas ou excessivas.');
        }

        [$dpiX, $dpiY, $dpiSource] = $this->detectDpi($path, $mime);

        return [
            'mime_type' => $mime,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'dpi_x' => $dpiX,
            'dpi_y' => $dpiY,
            'dpi_source' => $dpiSource,
        ];
    }

    /** @return array{0:?float,1:?float,2:?string} */
    private function detectDpi(string $path, string $mime): array
    {
        if ($mime === 'image/jpeg') {
            $exif = $this->jpegExifDpi($path);
            if ($exif[0] !== null && $exif[1] !== null) {
                return [$exif[0], $exif[1], 'EXIF'];
            }
            return $this->jpegJfifDpi($path);
        }

        if ($mime === 'image/png') {
            return $this->pngPhysDpi($path);
        }

        return [null, null, null];
    }

    /** @return array{0:?float,1:?float,2:?string} */
    private function jpegExifDpi(string $path): array
    {
        if (!function_exists('exif_read_data')) {
            return [null, null, null];
        }

        $data = @exif_read_data($path, 'IFD0', true, false);
        if (!is_array($data)) {
            return [null, null, null];
        }
        $ifd = isset($data['IFD0']) && is_array($data['IFD0']) ? $data['IFD0'] : $data;
        $x = $this->ratio(isset($ifd['XResolution']) ? $ifd['XResolution'] : null);
        $y = $this->ratio(isset($ifd['YResolution']) ? $ifd['YResolution'] : null);
        $unit = isset($ifd['ResolutionUnit']) ? (int) $ifd['ResolutionUnit'] : 2;
        if ($x === null || $y === null || $x <= 0 || $y <= 0) {
            return [null, null, null];
        }
        if ($unit === 3) { // pixels por centímetro
            $x *= 2.54;
            $y *= 2.54;
        } elseif ($unit !== 2) {
            return [null, null, null];
        }
        return [$x, $y, 'EXIF'];
    }

    /** @return array{0:?float,1:?float,2:?string} */
    private function jpegJfifDpi(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [null, null, null];
        }
        try {
            $soi = fread($handle, 2);
            if ($soi !== "\xFF\xD8") {
                return [null, null, null];
            }
            while (!feof($handle)) {
                $prefix = fread($handle, 1);
                if ($prefix !== "\xFF") {
                    continue;
                }
                do {
                    $marker = fread($handle, 1);
                } while ($marker === "\xFF");
                if ($marker === '' || $marker === false) {
                    break;
                }
                $code = ord($marker);
                if (in_array($code, [0xD8, 0xD9], true) || ($code >= 0xD0 && $code <= 0xD7)) {
                    continue;
                }
                $lenBytes = fread($handle, 2);
                if (!is_string($lenBytes) || strlen($lenBytes) !== 2) {
                    break;
                }
                $length = unpack('nlen', $lenBytes)['len'] ?? 0;
                if ($length < 2) {
                    break;
                }
                $payloadLength = $length - 2;
                if ($code === 0xE0) {
                    $payload = fread($handle, $payloadLength);
                    if (is_string($payload) && strlen($payload) >= 12 && substr($payload, 0, 5) === "JFIF\x00") {
                        $units = ord($payload[7]);
                        $x = unpack('nvalue', substr($payload, 8, 2))['value'] ?? 0;
                        $y = unpack('nvalue', substr($payload, 10, 2))['value'] ?? 0;
                        if ($x > 0 && $y > 0) {
                            if ($units === 1) {
                                return [(float) $x, (float) $y, 'JFIF'];
                            }
                            if ($units === 2) {
                                return [$x * 2.54, $y * 2.54, 'JFIF'];
                            }
                        }
                    }
                } else {
                    fseek($handle, $payloadLength, SEEK_CUR);
                }
                if (ftell($handle) > 262144) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
        return [null, null, null];
    }

    /** @return array{0:?float,1:?float,2:?string} */
    private function pngPhysDpi(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [null, null, null];
        }
        try {
            if (fread($handle, 8) !== "\x89PNG\r\n\x1a\n") {
                return [null, null, null];
            }
            while (!feof($handle)) {
                $lenBytes = fread($handle, 4);
                $type = fread($handle, 4);
                if (!is_string($lenBytes) || strlen($lenBytes) !== 4 || !is_string($type) || strlen($type) !== 4) {
                    break;
                }
                $length = unpack('Nlen', $lenBytes)['len'] ?? 0;
                if ($length < 0 || $length > 16_777_216) {
                    break;
                }
                $data = $length > 0 ? fread($handle, $length) : '';
                fread($handle, 4); // CRC
                if ($type === 'pHYs' && is_string($data) && strlen($data) === 9) {
                    $x = unpack('Nvalue', substr($data, 0, 4))['value'] ?? 0;
                    $y = unpack('Nvalue', substr($data, 4, 4))['value'] ?? 0;
                    $unit = ord($data[8]);
                    if ($unit === 1 && $x > 0 && $y > 0) {
                        return [$x * 0.0254, $y * 0.0254, 'PNG_PHYS'];
                    }
                }
                if ($type === 'IEND' || ftell($handle) > 262144) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
        return [null, null, null];
    }

    private function ratio(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (str_contains($value, '/')) {
            [$a, $b] = array_pad(explode('/', $value, 2), 2, '0');
            $den = (float) $b;
            return $den == 0.0 ? null : ((float) $a / $den);
        }
        return is_numeric($value) ? (float) $value : null;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'A imagem excede o limite de upload permitido.',
            UPLOAD_ERR_PARTIAL => 'O upload da imagem foi interrompido antes de concluir.',
            UPLOAD_ERR_NO_FILE => 'Selecione a fotografia e a assinatura para concluir a coleta.',
            default => 'Falha no envio da imagem biométrica (código ' . $error . ').',
        };
    }
}
