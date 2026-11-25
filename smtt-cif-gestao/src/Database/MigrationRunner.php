<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Database;

use mysqli;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    public function __construct(
        private readonly mysqli $db,
        private readonly string $migrationsPath
    ) {
    }

    /** @return list<array{version:string,description:string,applied:bool,checksum_ok:bool}> */
    public function status(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->appliedMigrations();
        $status = [];

        foreach ($this->discover() as $migration) {
            $version = $migration['version'];
            $checksum = $migration['checksum'];
            $stored = $applied[$version]['checksum'] ?? null;

            $status[] = [
                'version' => $version,
                'description' => $migration['description'],
                'applied' => $stored !== null,
                'checksum_ok' => $stored === null || hash_equals($stored, $checksum),
            ];
        }

        return $status;
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->appliedMigrations();
        $installedNow = [];

        foreach ($this->discover() as $migration) {
            $version = $migration['version'];

            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version]['checksum'], $migration['checksum'])) {
                    throw new RuntimeException(sprintf(
                        'A migration aplicada %s foi alterada após sua execução. Restaure o arquivo original.',
                        $version
                    ));
                }
                continue;
            }

            $started = hrtime(true);

            try {
                foreach ($migration['up'] as $statement) {
                    $sql = trim($statement);
                    if ($sql !== '') {
                        $this->db->query($sql);
                    }
                }
            } catch (Throwable $e) {
                throw new RuntimeException(sprintf('Falha na migration %s.', $version), 0, $e);
            }

            $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
            $stmt = $this->db->prepare(
                'INSERT INTO schema_migrations (version, description, checksum, applied_at, duration_ms) ' .
                'VALUES (?, ?, ?, UTC_TIMESTAMP(6), ?)'
            );
            $description = $migration['description'];
            $checksum = $migration['checksum'];
            $stmt->bind_param('sssi', $version, $description, $checksum, $durationMs);
            $stmt->execute();
            $stmt->close();

            $installedNow[] = $version;
            $applied[$version] = ['checksum' => $checksum];
        }

        return $installedNow;
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at DATETIME(6) NOT NULL,
    duration_ms INT UNSIGNED NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    /** @return array<string,array{checksum:string}> */
    private function appliedMigrations(): array
    {
        $resultSet = $this->db->query('SELECT version, checksum FROM schema_migrations ORDER BY version');
        $result = [];

        while ($row = $resultSet->fetch_assoc()) {
            $result[(string) $row['version']] = ['checksum' => (string) $row['checksum']];
        }

        $resultSet->free();
        return $result;
    }

    /**
     * @return list<array{version:string,description:string,up:list<string>,checksum:string,path:string}>
     */
    private function discover(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);
        $migrations = [];
        $seen = [];

        foreach ($files as $file) {
            $definition = require $file;
            if (!is_array($definition)) {
                throw new RuntimeException('Migration inválida: ' . basename($file));
            }

            $version = $definition['version'] ?? null;
            $description = $definition['description'] ?? null;
            $up = $definition['up'] ?? null;

            if (!is_string($version) || $version === '' || !is_string($description) || !is_array($up)) {
                throw new RuntimeException('Estrutura de migration inválida: ' . basename($file));
            }

            if (isset($seen[$version])) {
                throw new RuntimeException('Versão de migration duplicada: ' . $version);
            }
            $seen[$version] = true;

            foreach ($up as $statement) {
                if (!is_string($statement)) {
                    throw new RuntimeException('Toda instrução de migration deve ser SQL textual: ' . $version);
                }
            }

            $migrations[] = [
                'version' => $version,
                'description' => $description,
                'up' => array_values($up),
                'checksum' => hash_file('sha256', $file),
                'path' => $file,
            ];
        }

        return $migrations;
    }
}
