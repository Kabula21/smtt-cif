<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Database;

use mysqli;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    public function __construct(private readonly mysqli $db, private readonly string $path)
    {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $installed = [];
        foreach ($this->discover() as $migration) {
            $version = $migration['version'];
            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $migration['checksum'])) {
                    throw new RuntimeException('A migration aplicada ' . $version . ' foi alterada.');
                }
                continue;
            }
            $started = hrtime(true);
            try {
                foreach ($migration['up'] as $sql) {
                    if (trim($sql) !== '') {
                        $this->db->query($sql);
                    }
                }
            } catch (Throwable $e) {
                throw new RuntimeException('Falha na migration ' . $version . '.', 0, $e);
            }
            $duration = (int)round((hrtime(true) - $started) / 1_000_000);
            $stmt = $this->db->prepare('INSERT INTO schema_migrations(version,description,checksum,applied_at,duration_ms) VALUES(?,?,?,UTC_TIMESTAMP(6),?)');
            $stmt->bind_param('sssi', $version, $migration['description'], $migration['checksum'], $duration);
            $stmt->execute();
            $stmt->close();
            $installed[] = $version;
        }
        return $installed;
    }

    public function latestVersion(): string
    {
        $this->ensureTable();
        $result = $this->db->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1');
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? (string)$row['version'] : 'none';
    }

    private function ensureTable(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) NOT NULL, description VARCHAR(255) NOT NULL, checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, applied_at DATETIME(6) NOT NULL, duration_ms INT UNSIGNED NULL, PRIMARY KEY(version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return array<string,string> */
    private function applied(): array
    {
        $result = $this->db->query('SELECT version,checksum FROM schema_migrations');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[(string)$row['version']] = (string)$row['checksum'];
        }
        $result->free();
        return $rows;
    }

    /** @return list<array{version:string,description:string,up:list<string>,checksum:string}> */
    private function discover(): array
    {
        $files = glob(rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);
        $out = [];
        $seen = [];
        foreach ($files as $file) {
            $d = require $file;
            if (!is_array($d) || !is_string($d['version'] ?? null) || !is_string($d['description'] ?? null) || !is_array($d['up'] ?? null)) {
                throw new RuntimeException('Migration inválida: ' . basename($file));
            }
            if (isset($seen[$d['version']])) {
                throw new RuntimeException('Migration duplicada: ' . $d['version']);
            }
            $seen[$d['version']] = true;
            $out[] = ['version' => $d['version'], 'description' => $d['description'], 'up' => array_values($d['up']), 'checksum' => hash_file('sha256', $file)];
        }
        return $out;
    }
}
