<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Security;

use JsonException;
use RuntimeException;
use SMTT\CIF\Gestao\Bootstrap\Environment;

final class SecretKeyStore
{
    private const FILE_NAME = 'data-keys.json';

    /** @var array{version:int,active_key_id:string,lookup_key:string,keys:array<string,string>}|null */
    private ?array $cache = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function activeKeyId(): string
    {
        return $this->load()['active_key_id'];
    }

    public function key(string $keyId): string
    {
        $data = $this->load();
        $encoded = $data['keys'][$keyId] ?? null;
        if (!is_string($encoded)) {
            throw new RuntimeException('A chave de proteção solicitada não existe no keyring CIF.');
        }

        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('O keyring CIF contém uma chave inválida.');
        }

        return $key;
    }

    public function path(): string
    {
        return rtrim($this->environment->secretDirectory(), '/\\') . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    public function lookupKey(): string
    {
        $data = $this->load();
        $key = base64_decode($data['lookup_key'], true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('O keyring CIF contém uma chave de lookup inválida.');
        }
        return $key;
    }

    /** @return array{version:int,active_key_id:string,lookup_key:string,keys:array<string,string>} */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->path();
        if (!is_file($path)) {
            $this->create($path);
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Não foi possível ler o keyring de proteção do Sistema CIF.');
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('O keyring de proteção do Sistema CIF está corrompido.', 0, $e);
        }

        if (
            !is_array($decoded)
            || ($decoded['version'] ?? null) !== 1
            || !is_string($decoded['active_key_id'] ?? null)
            || !is_string($decoded['lookup_key'] ?? null)
            || !is_array($decoded['keys'] ?? null)
        ) {
            throw new RuntimeException('O formato do keyring de proteção do Sistema CIF é inválido.');
        }

        /** @var array{version:int,active_key_id:string,lookup_key:string,keys:array<string,string>} $decoded */
        return $this->cache = $decoded;
    }

    private function create(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(
                'Não foi possível criar o diretório privado de chaves CIF. Defina SMTT_CIF_SECRET_DIR para um diretório gravável fora do webroot.'
            );
        }

        if (!is_writable($directory)) {
            throw new RuntimeException(
                'O diretório privado de chaves CIF não possui permissão de escrita. Ajuste SMTT_CIF_SECRET_DIR/permissões do servidor.'
            );
        }

        $master = random_bytes(32);
        $lookupKey = random_bytes(32);
        $fingerprint = substr(hash('sha256', $master), 0, 12);
        $keyId = 'data-' . gmdate('Ymd') . '-' . $fingerprint;
        $payload = [
            'version' => 1,
            'active_key_id' => $keyId,
            'lookup_key' => base64_encode($lookupKey),
            'keys' => [$keyId => base64_encode($master)],
        ];

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Não foi possível criar o keyring CIF.', 0, $e);
        }

        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o keyring privado do Sistema CIF.');
        }
        @chmod($tmp, 0600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Não foi possível finalizar a criação do keyring privado do Sistema CIF.');
        }
        @chmod($path, 0600);
    }
}
