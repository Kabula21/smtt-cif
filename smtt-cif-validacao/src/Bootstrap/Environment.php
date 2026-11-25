<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Bootstrap;

use RuntimeException;

final class Environment
{
    public function assertRuntime(): void
    {
        if (PHP_VERSION_ID < 80100) {
            throw new RuntimeException('PHP 8.1 ou superior é obrigatório.');
        }
        if (!extension_loaded('mysqli')) {
            throw new RuntimeException('A extensão mysqli é obrigatória.');
        }
        if (!extension_loaded('sodium') || !function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('A extensão sodium é obrigatória para validar assinaturas Ed25519.');
        }
    }

    /** @return array{host:string,port:?int,socket:?string,name:string,user:string,password:string,charset:string,credential_source:string} */
    public function database(): array
    {
        $rawHost = $this->value('SMTT_CIF_PUBLIC_DB_HOST');
        $source = 'SMTT_CIF_PUBLIC_DB_*';
        if ($rawHost === null) {
            $rawHost = $this->wordpressConstant('DB_HOST', 'localhost') ?? 'localhost';
            $source = 'WordPress DB_*';
        }
        $parsed = $this->parseMysqlHost($rawHost);
        $explicitPort = $this->value('SMTT_CIF_PUBLIC_DB_PORT');
        $port = $explicitPort !== null ? (int)$explicitPort : $parsed['port'];
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new RuntimeException('SMTT_CIF_PUBLIC_DB_PORT é inválida.');
        }

        $name = $this->value('SMTT_CIF_PUBLIC_DB_NAME', 'smtt_cif_public');
        if ($name === null || preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
            throw new RuntimeException('SMTT_CIF_PUBLIC_DB_NAME deve conter apenas letras, números e underscore.');
        }

        $user = $this->value('SMTT_CIF_PUBLIC_DB_USER');
        if ($user === null) {
            $user = $this->wordpressConstant('DB_USER');
            $source = $source === 'SMTT_CIF_PUBLIC_DB_*' ? 'configuração mista' : 'WordPress DB_*';
        }
        if ($user === null || $user === '') {
            throw new RuntimeException('Usuário do banco público não configurado.');
        }

        $password = $this->rawValue('SMTT_CIF_PUBLIC_DB_PASSWORD');
        if ($password === null) {
            $password = $this->wordpressConstantRaw('DB_PASSWORD') ?? '';
            $source = $source === 'SMTT_CIF_PUBLIC_DB_*' ? 'configuração mista' : 'WordPress DB_*';
        }

        $charset = $this->value('SMTT_CIF_PUBLIC_DB_CHARSET', 'utf8mb4');
        if ($charset !== 'utf8mb4') {
            throw new RuntimeException('O charset do banco público CIF deve ser utf8mb4.');
        }

        return [
            'host' => $parsed['host'],
            'port' => $port,
            'socket' => $parsed['socket'],
            'name' => $name,
            'user' => $user,
            'password' => $password,
            'charset' => $charset,
            'credential_source' => $source,
        ];
    }

    public function value(string $name, ?string $default = null): ?string
    {
        $value = $this->rawValue($name);
        return ($value === null || $value === '') ? $default : $value;
    }

    private function rawValue(string $name): ?string
    {
        if (defined($name)) {
            $value = constant($name);
            return is_scalar($value) ? (string)$value : null;
        }
        $value = getenv($name);
        return $value === false ? null : (string)$value;
    }

    private function wordpressConstant(string $name, ?string $default = null): ?string
    {
        $value = $this->wordpressConstantRaw($name);
        return ($value === null || $value === '') ? $default : $value;
    }

    private function wordpressConstantRaw(string $name): ?string
    {
        if (!defined($name)) {
            return null;
        }
        $value = constant($name);
        return is_scalar($value) ? (string)$value : null;
    }

    /** @return array{host:string,port:?int,socket:?string} */
    private function parseMysqlHost(string $rawHost): array
    {
        $rawHost = trim($rawHost) ?: 'localhost';
        if (preg_match('#^([^:]+):(\d+):(/.+)$#', $rawHost, $m) === 1) {
            return ['host' => $m[1], 'port' => (int)$m[2], 'socket' => $m[3]];
        }
        if (preg_match('#^([^:]+):(/.+)$#', $rawHost, $m) === 1) {
            return ['host' => $m[1], 'port' => null, 'socket' => $m[2]];
        }
        if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $rawHost, $m) === 1) {
            return ['host' => $m[1], 'port' => isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null, 'socket' => null];
        }
        if (preg_match('/^(.+):(\d+)$/', $rawHost, $m) === 1) {
            return ['host' => $m[1], 'port' => (int)$m[2], 'socket' => null];
        }
        if (str_starts_with($rawHost, '/')) {
            return ['host' => 'localhost', 'port' => null, 'socket' => $rawHost];
        }
        return ['host' => $rawHost, 'port' => null, 'socket' => null];
    }
}
