<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Storage;

use RuntimeException;
use SMTT\CIF\Gestao\Bootstrap\Environment;
use SMTT\CIF\Gestao\Security\SecretKeyStore;
use SMTT\CIF\Gestao\Support\Uuid;

final class PrivateStorage
{
    private const VERSION = "\x01";

    public function __construct(
        private readonly Environment $environment,
        private readonly SecretKeyStore $keys
    ) {
    }

    /**
     * @return array{uuid:string,storage_backend:string,storage_key:string,mime_type:string,size:int,sha256:string,key_id:string}
     */
    public function storeImage(string $sourcePath, string $mimeType, string $category): array
    {
        $extension = $mimeType === 'image/png' ? 'png' : 'jpg';
        return $this->storeFile($sourcePath, $mimeType, $category, $extension);
    }

    /**
     * Armazena qualquer artefato privado usando criptografia autenticada.
     * A extensão informada é somente operacional; os bytes persistidos nunca
     * permanecem em formato aberto no storage.
     *
     * @return array{uuid:string,storage_backend:string,storage_key:string,mime_type:string,size:int,sha256:string,key_id:string}
     */
    public function storeFile(string $sourcePath, string $mimeType, string $category, string $extension = 'bin'): array
    {
        $this->assertSodium();
        $plain = @file_get_contents($sourcePath);
        if (!is_string($plain) || $plain === '') {
            throw new RuntimeException('Não foi possível ler o arquivo para armazenamento privado.');
        }

        $category = strtolower(trim($category));
        if ($category === '' || preg_match('/^[a-z0-9_-]+$/', $category) !== 1) {
            throw new RuntimeException('Categoria de arquivo privado inválida.');
        }
        $extension = strtolower(trim($extension));
        if ($extension === '' || preg_match('/^[a-z0-9]{1,12}$/', $extension) !== 1) {
            $extension = 'bin';
        }

        $uuid = Uuid::v4();
        $keyId = $this->keys->activeKeyId();
        $master = $this->keys->key($keyId);
        $encKey = hash_hmac('sha256', 'SMTT-CIF:PRIVATE-FILE:ENC:V1', $master, true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $encKey);
        $payload = self::VERSION . $nonce . $cipher;
        $sha256 = hash('sha256', $plain, true);

        sodium_memzero($encKey);
        sodium_memzero($master);

        $storageKey = $category . '/' . gmdate('Y/m') . '/' . $uuid . '.' . $extension . '.enc';
        $path = $this->pathFor($storageKey);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório de armazenamento privado.');
        }
        @chmod($directory, 0700);

        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o arquivo no armazenamento privado.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Não foi possível finalizar a gravação do arquivo privado.');
        }
        @chmod($path, 0600);

        return [
            'uuid' => $uuid,
            'storage_backend' => 'LOCAL_ENCRYPTED',
            'storage_key' => $storageKey,
            'mime_type' => $mimeType,
            'size' => strlen($plain),
            'sha256' => $sha256,
            'key_id' => $keyId,
        ];
    }

    /**
     * @return array{uuid:string,storage_backend:string,storage_key:string,mime_type:string,size:int,sha256:string,key_id:string}
     */
    public function storeBytes(string $bytes, string $mimeType, string $category, string $extension = 'bin'): array
    {
        if ($bytes === '') {
            throw new RuntimeException('Não é possível armazenar um artefato vazio.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'smtt-cif-');
        if (!is_string($tmp) || @file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível preparar o artefato para armazenamento privado.');
        }
        try {
            return $this->storeFile($tmp, $mimeType, $category, $extension);
        } finally {
            @unlink($tmp);
        }
    }

    public function read(string $storageKey, string $keyId, string $expectedSha256): string
    {
        $this->assertSodium();
        $path = $this->pathFor($storageKey);
        $payload = @file_get_contents($path);
        $min = 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if (!is_string($payload) || strlen($payload) < $min || $payload[0] !== self::VERSION) {
            throw new RuntimeException('O arquivo privado está ausente ou em formato inválido.');
        }

        $master = $this->keys->key($keyId);
        $encKey = hash_hmac('sha256', 'SMTT-CIF:PRIVATE-FILE:ENC:V1', $master, true);
        $nonce = substr($payload, 1, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($payload, 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $encKey);
        sodium_memzero($encKey);
        sodium_memzero($master);
        if (!is_string($plain)) {
            throw new RuntimeException('Falha de integridade ao descriptografar o arquivo privado.');
        }
        if (!hash_equals($expectedSha256, hash('sha256', $plain, true))) {
            throw new RuntimeException('O arquivo privado não corresponde ao hash registrado no banco.');
        }
        return $plain;
    }

    public function delete(string $storageKey): void
    {
        $path = $this->pathFor($storageKey);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function directory(): string
    {
        return rtrim($this->environment->secretDirectory(), '/\\') . DIRECTORY_SEPARATOR . 'storage';
    }

    private function pathFor(string $storageKey): string
    {
        if ($storageKey === '' || str_contains($storageKey, '..') || str_starts_with($storageKey, '/') || str_starts_with($storageKey, '\\')) {
            throw new RuntimeException('Chave de armazenamento privada inválida.');
        }
        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storageKey);
        return $this->directory() . DIRECTORY_SEPARATOR . $relative;
    }

    private function assertSodium(): void
    {
        if (!extension_loaded('sodium') || !function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('A extensão PHP sodium é necessária para proteger os arquivos privados do Sistema CIF.');
        }
    }
}
