<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Sync;

use JsonException;
use RuntimeException;
use SMTT\CIF\Gestao\Bootstrap\Environment;

final class SyncSigningKeyStore
{
    private const FILE_NAME = 'sync-signing-keys.json';

    /** @var array{version:int,active_key_id:?string,keys:array<string,array{secret:string,public:string,created_at:string}>}|null */
    private ?array $cache = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function path(): string
    {
        return rtrim($this->environment->secretDirectory(), '/\\') . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /** @return array{key_id:string,secret:string,public:string,created_at:string} */
    public function generate(string $keyId): array
    {
        $this->assertSodium();
        $data = $this->load();
        if (isset($data['keys'][$keyId])) {
            throw new RuntimeException('Já existe uma chave de sincronização com este identificador.');
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $data['keys'][$keyId] = [
            'secret' => base64_encode($secret),
            'public' => base64_encode($public),
            'created_at' => $created,
        ];
        $this->save($data);
        sodium_memzero($secret);

        return ['key_id' => $keyId, 'secret' => '', 'public' => $public, 'created_at' => $created];
    }

    public function activate(string $keyId): void
    {
        $data = $this->load();
        if (!isset($data['keys'][$keyId])) {
            throw new RuntimeException('A chave Ed25519 selecionada não existe no armazenamento privado.');
        }
        $data['active_key_id'] = $keyId;
        $this->save($data);
    }

    public function activeKeyId(): ?string
    {
        return $this->load()['active_key_id'];
    }

    public function secret(string $keyId): string
    {
        $this->assertSodium();
        $row = $this->load()['keys'][$keyId] ?? null;
        if (!is_array($row)) {
            throw new RuntimeException('Chave privada de sincronização não localizada.');
        }
        $secret = base64_decode((string) $row['secret'], true);
        if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('A chave privada Ed25519 armazenada está inválida.');
        }
        return $secret;
    }

    public function publicKey(string $keyId): string
    {
        $row = $this->load()['keys'][$keyId] ?? null;
        if (!is_array($row)) {
            throw new RuntimeException('Chave pública de sincronização não localizada.');
        }
        $public = base64_decode((string) $row['public'], true);
        if (!is_string($public) || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('A chave pública Ed25519 armazenada está inválida.');
        }
        return $public;
    }

    /** @return array{version:int,active_key_id:?string,keys:array<string,array{secret:string,public:string,created_at:string}>} */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $path = $this->path();
        if (!is_file($path)) {
            $data = ['version' => 1, 'active_key_id' => null, 'keys' => []];
            $this->save($data);
            return $this->cache ?? $data;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Não foi possível ler o keyring Ed25519 da sincronização.');
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('O keyring Ed25519 da sincronização está corrompido.', 0, $e);
        }
        if (!is_array($decoded) || ($decoded['version'] ?? null) !== 1 || !array_key_exists('active_key_id', $decoded) || !is_array($decoded['keys'] ?? null)) {
            throw new RuntimeException('O formato do keyring Ed25519 da sincronização é inválido.');
        }
        /** @var array{version:int,active_key_id:?string,keys:array<string,array{secret:string,public:string,created_at:string}>} $decoded */
        return $this->cache = $decoded;
    }

    /** @param array{version:int,active_key_id:?string,keys:array<string,array{secret:string,public:string,created_at:string}>} $data */
    private function save(array $data): void
    {
        $directory = dirname($this->path());
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório privado das chaves de sincronização.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('O diretório privado das chaves de sincronização não possui permissão de escrita.');
        }
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Não foi possível serializar o keyring Ed25519.', 0, $e);
        }
        $path = $this->path();
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(5));
        if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o keyring Ed25519.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Não foi possível finalizar o keyring Ed25519.');
        }
        @chmod($path, 0600);
        $this->cache = $data;
    }

    private function assertSodium(): void
    {
        if (!extension_loaded('sodium') || !function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('A extensão PHP sodium é obrigatória para assinatura Ed25519.');
        }
    }
}
