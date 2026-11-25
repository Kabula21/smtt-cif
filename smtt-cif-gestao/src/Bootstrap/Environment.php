<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Bootstrap;

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
    }

    /**
     * Configuração do banco oficial CIF.
     *
     * Quando as constantes SMTT_CIF_DB_* não forem definidas, o plugin reutiliza
     * host/usuário/senha do WordPress apenas como credencial de conexão com o
     * servidor MySQL/MariaDB. O banco lógico continua separado e, por padrão,
     * chama-se `smtt_cif`.
     *
     * @return array{
     *   host:string,
     *   port:?int,
     *   socket:?string,
     *   name:string,
     *   user:string,
     *   password:string,
     *   charset:string,
     *   credential_source:string,
     *   transport_source:string
     * }
     */
    public function database(): array
    {
        $rawHost = $this->value('SMTT_CIF_DB_HOST');
        $source = 'SMTT_CIF_DB_*';
        $usingWordPressHost = false;

        if ($rawHost === null) {
            $rawHost = $this->wordpressConstant('DB_HOST', 'localhost');
            $source = 'WordPress DB_*';
            $usingWordPressHost = true;
        }

        $parsedHost = $this->parseMysqlHost($rawHost);

        // A conexão CIF usa mysqli dedicado e replica a semântica de conexão do wpdb.
        // Se DB_HOST não trouxer porta explícita, mantemos port=NULL para que
        // mysqli_real_connect utilize mysqli.default_port, em vez de forçar 3306.
        $transportSource = $usingWordPressHost
            ? 'mysqli usando WordPress DB_HOST'
            : 'mysqli usando SMTT_CIF_DB_HOST';

        if ($parsedHost['socket'] !== null) {
            $transportSource .= ' (socket explícito)';
        }

        $explicitPort = $this->value('SMTT_CIF_DB_PORT');
        $port = $explicitPort !== null ? (int) $explicitPort : $parsedHost['port'];

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new RuntimeException('SMTT_CIF_DB_PORT é inválida.');
        }

        $name = $this->value('SMTT_CIF_DB_NAME', 'smtt_cif');
        if ($name === null || $name === '' || preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
            throw new RuntimeException('SMTT_CIF_DB_NAME deve conter apenas letras, números e underscore, com até 64 caracteres.');
        }

        $user = $this->value('SMTT_CIF_DB_USER');
        if ($user === null) {
            $user = $this->wordpressConstant('DB_USER');
            $source = $source === 'SMTT_CIF_DB_*' ? 'configuração mista' : 'WordPress DB_*';
        }

        if ($user === null || $user === '') {
            throw new RuntimeException('Usuário de banco não configurado. Defina SMTT_CIF_DB_USER ou DB_USER.');
        }

        // Senha vazia é tecnicamente possível em ambiente local.
        $password = $this->rawValue('SMTT_CIF_DB_PASSWORD');
        if ($password === null) {
            $password = $this->wordpressConstantRaw('DB_PASSWORD') ?? '';
            $source = $source === 'SMTT_CIF_DB_*' ? 'configuração mista' : 'WordPress DB_*';
        }

        $charset = $this->value('SMTT_CIF_DB_CHARSET', 'utf8mb4');
        if ($charset !== 'utf8mb4') {
            throw new RuntimeException('O charset do banco CIF deve ser utf8mb4.');
        }

        return [
            'host' => $parsedHost['host'],
            'port' => $port,
            'socket' => $parsedHost['socket'],
            'name' => $name,
            'user' => $user,
            'password' => $password,
            'charset' => $charset,
            'credential_source' => $source,
            'transport_source' => $transportSource,
        ];
    }

    public function secretDirectory(): string
    {
        $configured = $this->value('SMTT_CIF_SECRET_DIR');
        if ($configured !== null && $configured !== '') {
            return rtrim($configured, '/\\');
        }

        if (!defined('ABSPATH')) {
            throw new RuntimeException('ABSPATH não está disponível para determinar o diretório privado do CIF.');
        }

        $wordpressRoot = rtrim((string) ABSPATH, '/\\');
        $parent = dirname($wordpressRoot);
        return $parent . DIRECTORY_SEPARATOR . 'smtt-cif-private';
    }

    public function value(string $name, ?string $default = null): ?string
    {
        $value = $this->rawValue($name);
        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    private function rawValue(string $name): ?string
    {
        if (defined($name)) {
            $value = constant($name);
            return is_scalar($value) ? (string) $value : null;
        }

        $value = getenv($name);
        if ($value === false) {
            return null;
        }

        return (string) $value;
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
        return is_scalar($value) ? (string) $value : null;
    }

    /** @return array{host:string,port:?int,socket:?string} */
    private function parseMysqlHost(string $rawHost): array
    {
        $rawHost = trim($rawHost);
        if ($rawHost === '') {
            $rawHost = 'localhost';
        }

        // Formato WordPress: host:port:/caminho/socket
        if (preg_match('#^([^:]+):(\d+):(/.+)$#', $rawHost, $matches) === 1) {
            return [
                'host' => $matches[1],
                'port' => (int) $matches[2],
                'socket' => $matches[3],
            ];
        }

        // Formato WordPress: host:/caminho/socket
        if (preg_match('#^([^:]+):(/.+)$#', $rawHost, $matches) === 1) {
            return [
                'host' => $matches[1],
                'port' => null,
                'socket' => $matches[2],
            ];
        }

        // IPv6 entre colchetes, opcionalmente com porta.
        if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $rawHost, $matches) === 1) {
            return [
                'host' => $matches[1],
                'port' => isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null,
                'socket' => null,
            ];
        }

        // host:porta
        if (preg_match('/^(.+):(\d+)$/', $rawHost, $matches) === 1) {
            return [
                'host' => $matches[1],
                'port' => (int) $matches[2],
                'socket' => null,
            ];
        }

        // Caminho de socket fornecido diretamente.
        if (str_starts_with($rawHost, '/')) {
            return [
                'host' => 'localhost',
                'port' => null,
                'socket' => $rawHost,
            ];
        }

        return [
            'host' => $rawHost,
            // Importante: quando DB_HOST não informa porta, não forçamos 3306.
            // O wpdb também entrega NULL ao mysqli_real_connect nesse caso,
            // permitindo que a extensão use mysqli.default_port. Ambientes locais
            // no Windows frequentemente usam uma porta MySQL dinâmica.
            'port' => null,
            'socket' => null,
        ];
    }
}
