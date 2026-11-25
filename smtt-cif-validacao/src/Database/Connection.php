<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Database;

use mysqli;
use RuntimeException;
use SMTT\CIF\Validacao\Bootstrap\Environment;

final class Connection
{
    private ?mysqli $mysqli = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function mysqli(): mysqli
    {
        if ($this->mysqli instanceof mysqli) {
            return $this->mysqli;
        }
        $db = $this->environment->database();
        $connection = $this->connect($db, $db['name']);
        $connection->query("SET time_zone = '+00:00'");
        return $this->mysqli = $connection;
    }

    /** @param array{host:string,port:?int,socket:?string,user:string,password:string,charset:string} $db */
    public function connect(array $db, ?string $database = null): mysqli
    {
        // LocalWP pode usar uma porta MySQL dinâmica enquanto mysqli.default_port
        // continua em 3306. Evitamos esperar o timeout da porta errada em cada request.
        $ports = [];
        if ($db['port'] !== null) {
            $ports[] = $db['port'];
        } else {
            $activePort = $this->wordpressActiveServerPort();
            $defaultPort = $this->mysqliDefaultPort();
            if ($activePort !== null) {
                $ports[] = $activePort;
            }
            if ($activePort === null || $activePort !== $defaultPort) {
                $ports[] = null;
            }
        }

        $failures = [];
        foreach (array_values(array_unique($ports, SORT_REGULAR)) as $port) {
            $mysqli = mysqli_init();
            if (!$mysqli instanceof mysqli) {
                throw new RuntimeException('Não foi possível inicializar mysqli.');
            }
            try {
                $ok = @$mysqli->real_connect($db['host'], $db['user'], $db['password'], $database, $port, $db['socket']);
                if ($ok !== true) {
                    $failures[] = ['errno' => (int)$mysqli->connect_errno, 'error' => (string)$mysqli->connect_error, 'port' => $port];
                    @$mysqli->close();
                    continue;
                }
                if (!$mysqli->set_charset($db['charset'])) {
                    $mysqli->close();
                    throw new RuntimeException('Não foi possível configurar utf8mb4 no banco público CIF.');
                }
                return $mysqli;
            } catch (\mysqli_sql_exception $e) {
                $failures[] = ['errno' => (int)$e->getCode(), 'error' => $e->getMessage(), 'port' => $port];
                @$mysqli->close();
            }
        }

        $last = $failures[count($failures) - 1] ?? ['errno' => 0, 'error' => 'falha desconhecida', 'port' => $db['port']];
        throw new RuntimeException(
            'Falha ao conectar ao banco público CIF (MySQL ' . (int)$last['errno'] . '): ' . (string)$last['error']
        );
    }

    private function mysqliDefaultPort(): int
    {
        $value = ini_get('mysqli.default_port');
        return is_numeric($value) && (int)$value > 0 ? (int)$value : 3306;
    }

    private function wordpressActiveServerPort(): ?int
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return null;
        }
        try {
            $value = $wpdb->get_var('SELECT @@port');
            if (is_numeric($value)) {
                $port = (int)$value;
                if ($port >= 1 && $port <= 65535) {
                    return $port;
                }
            }
        } catch (\Throwable) {
            // Fallback auxiliar; a falha principal permanece a da conexão dedicada.
        }
        return null;
    }
}
