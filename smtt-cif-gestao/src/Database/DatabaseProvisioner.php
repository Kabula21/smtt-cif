<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Database;

use mysqli;
use mysqli_sql_exception;
use RuntimeException;
use SMTT\CIF\Gestao\Bootstrap\Environment;

/**
 * Provisiona o banco lógico do Sistema CIF de forma não destrutiva.
 *
 * Usa conexão mysqli dedicada. Essa escolha é deliberada: o WordPress já usa
 * mysqli e, em ambientes com DB_HOST=localhost, mysqli utiliza o mesmo socket
 * padrão que comprovadamente funciona para o WordPress. Os dados CIF continuam
 * em banco separado e não usam o objeto global $wpdb.
 */
final class DatabaseProvisioner
{
    public function __construct(
        private readonly Environment $environment,
        private readonly Connection $connection
    ) {
    }

    /** @return bool true quando o banco foi criado nesta execução */
    public function ensureDatabase(): bool
    {
        $db = $this->environment->database();

        try {
            $server = $this->connection->connect($db, null);
        } catch (mysqli_sql_exception $e) {
            throw new RuntimeException(
                sprintf(
                    'Não foi possível conectar ao servidor MySQL/MariaDB usando %s e transporte %s. %s',
                    $db['credential_source'],
                    $db['transport_source'],
                    SqlDiagnostics::describeMysqli($e)
                ),
                0,
                $e
            );
        }

        try {
            $exists = $this->databaseExists($server, $db['name']);
            $created = false;

            if (!$exists) {
                try {
                    $quotedName = '`' . $db['name'] . '`';
                    $server->query(
                        'CREATE DATABASE ' . $quotedName .
                        ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                    );
                    $created = true;
                } catch (mysqli_sql_exception $e) {
                    throw new RuntimeException(
                        sprintf(
                            'O banco CIF "%s" não foi localizado entre os bancos acessíveis e não pôde ser criado automaticamente. %s ' .
                            'Se a hospedagem bloquear CREATE DATABASE ou exigir prefixo, crie o banco permitido e configure SMTT_CIF_DB_NAME.',
                            $db['name'],
                            SqlDiagnostics::describeMysqli($e)
                        ),
                        0,
                        $e
                    );
                }
            }

            try {
                if (!$server->select_db($db['name'])) {
                    throw new RuntimeException('mysqli::select_db retornou falha sem exceção.');
                }
                $this->assertDatabaseIdentity($server, $db['name']);
            } catch (mysqli_sql_exception $e) {
                throw new RuntimeException(
                    sprintf(
                        'O banco CIF "%s" %s, porém a credencial não conseguiu selecioná-lo. %s',
                        $db['name'],
                        $created ? 'foi criado' : 'já existe',
                        SqlDiagnostics::describeMysqli($e)
                    ),
                    0,
                    $e
                );
            }

            return $created;
        } finally {
            $server->close();
        }
    }

    private function databaseExists(mysqli $server, string $name): bool
    {
        try {
            $stmt = $server->prepare(
                'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1'
            );
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();
            return $exists;
        } catch (mysqli_sql_exception $e) {
            throw new RuntimeException(
                'Foi possível conectar ao servidor SQL, mas não foi possível verificar a existência do banco CIF. ' .
                SqlDiagnostics::describeMysqli($e),
                0,
                $e
            );
        }
    }

    private function assertDatabaseIdentity(mysqli $connection, string $expected): void
    {
        $result = $connection->query('SELECT DATABASE() AS current_database');
        $row = $result->fetch_assoc();
        $result->free();
        $actual = isset($row['current_database']) ? (string) $row['current_database'] : '';

        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'Conexão CIF apontou para um banco inesperado: esperado "%s", recebido "%s".',
                $expected,
                $actual
            ));
        }
    }
}
