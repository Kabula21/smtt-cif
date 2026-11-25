<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Database;

use mysqli;
use mysqli_sql_exception;
use RuntimeException;
use SMTT\CIF\Gestao\Bootstrap\Environment;

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

        try {
            $connection = $this->connect($db, $db['name']);
            $connection->query("SET time_zone = '+00:00'");
        } catch (mysqli_sql_exception $e) {
            throw new RuntimeException(
                sprintf(
                    'Não foi possível conectar ao banco oficial "%s" usando %s. %s',
                    $db['name'],
                    $db['credential_source'],
                    SqlDiagnostics::describeMysqli($e)
                ),
                0,
                $e
            );
        }

        return $this->mysqli = $connection;
    }

    /**
     * Abre uma conexão mysqli dedicada ao banco CIF. Não reutiliza o objeto
     * global $wpdb e não acessa tabelas do WordPress.
     *
     * O comportamento de porta replica o wpdb: quando DB_HOST não informa uma
     * porta, entregamos NULL ao mysqli_real_connect. Em ambientes locais no
     * Windows isso permite usar mysqli.default_port. Se mesmo assim falhar, e
     * o WordPress já possuir uma conexão ativa, tentamos uma segunda vez com a
     * porta informada pelo próprio servidor ao WordPress (SELECT @@port).
     *
     * @param array{host:string,port:?int,socket:?string,user:string,password:string,charset:string} $db
     */
    public function connect(array $db, ?string $database = null): mysqli
    {
        // Em ambientes como LocalWP o WordPress frequentemente está conectado a uma
        // porta MySQL dinâmica (ex.: 100xx), enquanto mysqli.default_port continua 3306.
        // Tentar 3306 primeiro provoca um timeout de vários segundos EM CADA request CIF.
        // Quando DB_HOST não traz porta explícita, reutilizamos primeiro a porta da conexão
        // WordPress já ativa; só então tentamos o default como fallback.
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

        foreach ($ports as $port) {
            $mysqli = mysqli_init();
            if (!$mysqli instanceof mysqli) {
                throw new RuntimeException('Não foi possível inicializar a extensão mysqli.');
            }

            try {
                // O @ impede que o warning nativo do mysqli seja impresso no HTML.
                // O erro é convertido abaixo em diagnóstico controlado.
                $ok = @$mysqli->real_connect(
                    $db['host'],
                    $db['user'],
                    $db['password'],
                    $database,
                    $port,
                    $db['socket']
                );

                if ($ok !== true) {
                    $failures[] = [
                        'errno' => (int) $mysqli->connect_errno,
                        'error' => (string) $mysqli->connect_error,
                        'port' => $port,
                    ];
                    @$mysqli->close();
                    continue;
                }

                if (!$mysqli->set_charset($db['charset'])) {
                    $mysqli->close();
                    throw new RuntimeException('Não foi possível configurar charset ' . $db['charset'] . ' na conexão CIF.');
                }

                return $mysqli;
            } catch (mysqli_sql_exception $e) {
                @$mysqli->close();
                throw $e;
            }
        }

        $last = $failures[count($failures) - 1] ?? ['errno' => 0, 'error' => '', 'port' => $db['port']];
        $tried = array_map(
            fn($port): string => $db['host'] . ':' . ($port ?? ('default(' . $this->mysqliDefaultPort() . ')')),
            $ports
        );

        throw new RuntimeException(
            SqlDiagnostics::describeConnectFailure(
                (int) $last['errno'],
                (string) $last['error'],
                $db['host'],
                $last['port'] !== null ? (int) $last['port'] : null
            ) . ' Endpoints tentados: ' . implode(', ', array_unique($tried)) . '.'
        );
    }

    private function mysqliDefaultPort(): int
    {
        $value = ini_get('mysqli.default_port');
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 3306;
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
                $port = (int) $value;
                if ($port >= 1 && $port <= 65535) {
                    return $port;
                }
            }
        } catch (\Throwable) {
            // O fallback é apenas auxiliar. Mantemos a falha original de conexão.
        }

        return null;
    }
}
