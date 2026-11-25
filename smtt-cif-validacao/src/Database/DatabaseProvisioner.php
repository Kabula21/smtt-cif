<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Database;

use RuntimeException;
use SMTT\CIF\Validacao\Bootstrap\Environment;

final class DatabaseProvisioner
{
    public function __construct(private readonly Environment $environment, private readonly Connection $connection)
    {
    }

    public function ensureDatabase(): bool
    {
        $db = $this->environment->database();
        $server = $this->connection->connect($db, null);
        try {
            $stmt = $server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=? LIMIT 1');
            $stmt->bind_param('s', $db['name']);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();
            if (!$exists) {
                $quoted = '`' . $db['name'] . '`';
                try {
                    $server->query('CREATE DATABASE ' . $quoted . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                } catch (\Throwable $e) {
                    throw new RuntimeException('O banco "' . $db['name'] . '" não existe e não pôde ser criado automaticamente. Crie-o ou conceda CREATE DATABASE.', 0, $e);
                }
            }
            if (!$server->select_db($db['name'])) {
                throw new RuntimeException('O banco público foi localizado, mas não pôde ser selecionado.');
            }
            return !$exists;
        } finally {
            $server->close();
        }
    }
}
