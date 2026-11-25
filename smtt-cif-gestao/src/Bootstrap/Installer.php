<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Bootstrap;

use SMTT\CIF\Gestao\Database\Connection;
use SMTT\CIF\Gestao\Database\DatabaseProvisioner;
use SMTT\CIF\Gestao\Database\MigrationRunner;

final class Installer
{
    public function __construct(
        private readonly DatabaseProvisioner $provisioner,
        private readonly Connection $connection,
        private readonly string $migrationsPath
    ) {
    }

    /**
     * Garante de forma idempotente que o banco exista e, somente depois disso,
     * abre a conexão mysqli dedicada selecionando o banco CIF e aplica as
     * migrations pendentes.
     *
     * @return array{database_created:bool,migrations_applied:list<string>}
     */
    public function ensureInstalled(): array
    {
        $created = $this->provisioner->ensureDatabase();

        $migrations = new MigrationRunner(
            $this->connection->mysqli(),
            $this->migrationsPath
        );

        $applied = $migrations->migrate();

        return [
            'database_created' => $created,
            'migrations_applied' => $applied,
        ];
    }
}
