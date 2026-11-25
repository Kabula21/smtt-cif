<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Command;

use SMTT\CIF\Gestao\Bootstrap\Container;

final class MigrateCommand
{
    public function __construct(private readonly Container $container)
    {
    }

    public function __invoke(array $args, array $assocArgs): void
    {
        $result = $this->container->installer()->ensureInstalled();
        $installed = $result['migrations_applied'];

        if ($result['database_created']) {
            \WP_CLI::log('Banco smtt_cif criado automaticamente.');
        }

        if ($installed === []) {
            \WP_CLI::success('Banco existente e nenhuma migration pendente.');
            return;
        }

        foreach ($installed as $version) {
            \WP_CLI::log('Aplicada: ' . $version);
        }

        \WP_CLI::success(sprintf('%d migration(s) aplicada(s).', count($installed)));
    }
}
