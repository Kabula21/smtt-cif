<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Bootstrap;

use SMTT\CIF\Validacao\Database\Connection;
use SMTT\CIF\Validacao\Database\DatabaseProvisioner;
use SMTT\CIF\Validacao\Database\MigrationRunner;

final class Installer
{
    public function __construct(
        private readonly DatabaseProvisioner $provisioner,
        private readonly Connection $connection,
        private readonly string $migrationsPath
    ) {
    }

    /** @return array{database_created:bool,migrations_applied:list<string>} */
    public function ensureInstalled(): array
    {
        $created = $this->provisioner->ensureDatabase();
        $runner = new MigrationRunner($this->connection->mysqli(), $this->migrationsPath);
        return ['database_created' => $created, 'migrations_applied' => $runner->migrate()];
    }
}
