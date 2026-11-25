<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Command;

use SMTT\CIF\Gestao\Bootstrap\Container;

final class SystemCheckCommand
{
    public function __construct(private readonly Container $container)
    {
    }

    public function __invoke(array $args, array $assocArgs): void
    {
        $checks = [];
        $checks[] = ['item' => 'PHP', 'status' => PHP_VERSION_ID >= 80100 ? 'OK' : 'ERRO', 'detail' => PHP_VERSION];
        $checks[] = ['item' => 'mysqli', 'status' => extension_loaded('mysqli') ? 'OK' : 'ERRO', 'detail' => extension_loaded('mysqli') ? 'carregada' : 'ausente'];
        $checks[] = ['item' => 'sodium', 'status' => extension_loaded('sodium') ? 'OK' : 'ERRO', 'detail' => extension_loaded('sodium') ? 'carregada' : 'necessária para proteção de dados e assinatura Ed25519'];
        $checks[] = ['item' => 'WordPress', 'status' => defined('ABSPATH') ? 'OK' : 'ERRO', 'detail' => defined('WP_VERSION') ? (string) WP_VERSION : (get_bloginfo('version') ?: 'desconhecida')];

        try {
            $db = $this->container->environment()->database();
            $endpoint = $db['socket'] !== null
                ? 'socket:' . $db['socket']
                : $db['host'] . ':' . ($db['port'] ?? ('default(' . (string) ini_get('mysqli.default_port') . ')'));

            $checks[] = [
                'item' => 'Configuração DB',
                'status' => 'OK',
                'detail' => sprintf('%s/%s (%s; transporte: %s)', $endpoint, $db['name'], $db['credential_source'], $db['transport_source']),
            ];

            $this->container->databaseProvisioner()->ensureDatabase();
            $mysqli = $this->container->connection()->mysqli();
            $result = $mysqli->query('SELECT VERSION() AS version_value');
            $row = $result->fetch_assoc();
            $result->free();
            $version = (string) ($row['version_value'] ?? 'desconhecida');
            $checks[] = ['item' => 'Conexão smtt_cif', 'status' => 'OK', 'detail' => $version];

            $status = $this->container->migrations()->status();
            $pending = count(array_filter($status, static fn(array $row): bool => !$row['applied']));
            $invalid = count(array_filter($status, static fn(array $row): bool => !$row['checksum_ok']));
            $checks[] = [
                'item' => 'Migrations',
                'status' => $invalid > 0 ? 'ERRO' : ($pending > 0 ? 'PENDENTE' : 'OK'),
                'detail' => sprintf('%d pendente(s), %d checksum(s) divergente(s)', $pending, $invalid),
            ];

            try {
                $path = $this->container->secretKeyStore()->path();
                $this->container->secretKeyStore()->activeKeyId();
                $checks[] = ['item' => 'Keyring privado', 'status' => 'OK', 'detail' => $path];
            } catch (\Throwable $e) {
                $checks[] = ['item' => 'Keyring privado', 'status' => 'ERRO', 'detail' => $e->getMessage()];
            }

            try {
                $sync = $this->container->syncConfigService()->overview();
                $checks[] = [
                    'item' => 'Chave Ed25519 sync',
                    'status' => !empty($sync['key_consistent']) ? 'OK' : 'ERRO',
                    'detail' => ($sync['active_key']['key_id'] ?? null) ? (string)$sync['active_key']['key_id'] : 'nenhuma chave ativa',
                ];
            } catch (\Throwable $e) {
                $checks[] = ['item' => 'Chave Ed25519 sync', 'status' => 'ERRO', 'detail' => $e->getMessage()];
            }

            try {
                $storageDir = $this->container->privateStorage()->directory();
                $parent = dirname($storageDir);
                $writable = (is_dir($storageDir) && is_writable($storageDir)) || (is_dir($parent) && is_writable($parent));
                $checks[] = ['item' => 'Storage biométrico', 'status' => $writable ? 'OK' : 'ERRO', 'detail' => $storageDir];
            } catch (\Throwable $e) {
                $checks[] = ['item' => 'Storage biométrico', 'status' => 'ERRO', 'detail' => $e->getMessage()];
            }
        } catch (\Throwable $e) {
            $checks[] = ['item' => 'Banco smtt_cif', 'status' => 'ERRO', 'detail' => $e->getMessage()];
        }

        \WP_CLI\Utils\format_items('table', $checks, ['item', 'status', 'detail']);
    }
}
