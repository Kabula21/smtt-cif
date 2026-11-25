<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Command;

use SMTT\CIF\Gestao\Bootstrap\Container;

final class CommandRegistrar
{
    public function __construct(private readonly Container $container)
    {
    }

    public function register(): void
    {
        \WP_CLI::add_command('smtt-cif system-check', new SystemCheckCommand($this->container));
        \WP_CLI::add_command('smtt-cif migrate', new MigrateCommand($this->container));
        \WP_CLI::add_command('smtt-cif migration-status', new MigrationStatusCommand($this->container));
        \WP_CLI::add_command('smtt-cif user grant-admin', $this->container->grantAdminCommand());
        \WP_CLI::add_command('smtt-cif sync run', new SyncRunCommand($this->container->syncWorker()));
        \WP_CLI::add_command('smtt-cif sync status', new SyncStatusCommand($this->container->syncRepository(), $this->container->syncConfigService()));
        \WP_CLI::add_command('smtt-cif sync heartbeat', new SyncHeartbeatCommand($this->container->heartbeatService()));
        \WP_CLI::add_command('smtt-cif sync reconcile', new SyncReconcileCommand($this->container->reconciliationService()));
        \WP_CLI::add_command('smtt-cif sync rebuild-public', new SyncRebuildCommand($this->container->reconciliationService()));
    }
}
