<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Biometry;

use InvalidArgumentException;

final class ImageNormalizer
{
    private const PHOTO_MIN_SHORT = 360;
    private const PHOTO_MIN_LONG = 480;
    private const PHOTO_MAX_WIDTH = 1600;
    private const PHOTO_MAX_HEIGHT = 2000;

    private const SIGNATURE_MIN_WIDTH = 300;
    private const SIGNATURE_MIN_HEIGHT = 60;
    private const SIGNATURE_MAX_WIDTH = 1600;
    private const SIGNATURE_MAX_HEIGHT = 600;

    private const REWRITE_THRESHOLD_BYTES = 3_145_728; // 3 MiB

    /**
     * @param array{mime_type:string,size:int,width:int,height:int,dpi_x:?float,dpi_y:?float,dpi_source:?string} $info
     * @return array{path:string,mime_type:string,temporary:bool,optimized:bool,original_width:int,original_height:int}
     */
    public function prepare(string $sourcePath, array $info, string $kind): array
    {
        $kind = strtolower(trim($kind));
        $this->validateDimensions($kind, (int) $info['width'], (int) $info['height']);

        [$maxWidth, $maxHeight] = $kind === 'photo'
            ? [self::PHOTO_MAX_WIDTH, self::PHOTO_MAX_HEIGHT]
            : [self::SIGNATURE_MAX_WIDTH, self::SIGNATURE_MAX_HEIGHT];

        $needsResize = (int) $info['width'] > $maxWidth || (int) $info['height'] > $maxHeight;
        $needsRewrite = $needsResize || (int) $info['size'] > self::REWRITE_THRESHOLD_BYTES;

        if (!$needsRewrite || !function_exists('wp_get_image_editor')) {
            return [
                'path' => $sourcePath,
                'mime_type' => (string) $info['mime_type'],
                'temporary' => false,
                'optimized' => false,
                'original_width' => (int) $info['width'],
                'original_height' => (int) $info['height'],
            ];
        }

        $editor = wp_get_image_editor($sourcePath);
        if (is_wp_error($editor)) {
            return [
                'path' => $sourcePath,
                'mime_type' => (string) $info['mime_type'],
                'temporary' => false,
                'optimized' => false,
                'original_width' => (int) $info['width'],
                'original_height' => (int) $info['height'],
            ];
        }

        $size = $editor->get_size();
        $width = isset($size['width']) ? (int) $size['width'] : (int) $info['width'];
        $height = isset($size['height']) ? (int) $size['height'] : (int) $info['height'];
        if ($width > $maxWidth || $height > $maxHeight) {
            $scale = min($maxWidth / max(1, $width), $maxHeight / max(1, $height), 1.0);
            $targetWidth = max(1, (int) floor($width * $scale));
            $targetHeight = max(1, (int) floor($height * $scale));
            $resized = $editor->resize($targetWidth, $targetHeight, false);
            if (is_wp_error($resized)) {
                return [
                    'path' => $sourcePath,
                    'mime_type' => (string) $info['mime_type'],
                    'temporary' => false,
                    'optimized' => false,
                    'original_width' => (int) $info['width'],
                    'original_height' => (int) $info['height'],
                ];
            }
        }

        if ((string) $info['mime_type'] === 'image/jpeg' && method_exists($editor, 'set_quality')) {
            $editor->set_quality(92);
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'smtt-cif-img-');
        if (!is_string($tmpBase)) {
            return [
                'path' => $sourcePath,
                'mime_type' => (string) $info['mime_type'],
                'temporary' => false,
                'optimized' => false,
                'original_width' => (int) $info['width'],
                'original_height' => (int) $info['height'],
            ];
        }
        @unlink($tmpBase);
        $extension = (string) $info['mime_type'] === 'image/png' ? 'png' : 'jpg';
        $target = $tmpBase . '.' . $extension;
        $saved = $editor->save($target, (string) $info['mime_type']);
        if (is_wp_error($saved) || !is_file($target) || filesize($target) === 0) {
            @unlink($target);
            return [
                'path' => $sourcePath,
                'mime_type' => (string) $info['mime_type'],
                'temporary' => false,
                'optimized' => false,
                'original_width' => (int) $info['width'],
                'original_height' => (int) $info['height'],
            ];
        }

        return [
            'path' => $target,
            'mime_type' => (string) $info['mime_type'],
            'temporary' => true,
            'optimized' => true,
            'original_width' => (int) $info['width'],
            'original_height' => (int) $info['height'],
        ];
    }

    public function validateDimensions(string $kind, int $width, int $height): void
    {
        if ($kind === 'photo') {
            $short = min($width, $height);
            $long = max($width, $height);
            if ($short < self::PHOTO_MIN_SHORT || $long < self::PHOTO_MIN_LONG) {
                throw new InvalidArgumentException(sprintf(
                    'A fotografia possui %d×%d px. Utilize uma imagem com pelo menos %d px no lado menor e %d px no lado maior.',
                    $width,
                    $height,
                    self::PHOTO_MIN_SHORT,
                    self::PHOTO_MIN_LONG
                ));
            }
            return;
        }

        if ($kind === 'signature') {
            if ($width < self::SIGNATURE_MIN_WIDTH || $height < self::SIGNATURE_MIN_HEIGHT) {
                throw new InvalidArgumentException(sprintf(
                    'A assinatura possui %d×%d px. Utilize uma imagem com pelo menos %d×%d px.',
                    $width,
                    $height,
                    self::SIGNATURE_MIN_WIDTH,
                    self::SIGNATURE_MIN_HEIGHT
                ));
            }
            return;
        }

        throw new InvalidArgumentException('Tipo de imagem biométrica inválido.');
    }
}
