<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Command;

use SMTT\CIF\Gestao\Bootstrap\Container;

final class MigrationStatusCommand
{
    public function __construct(private readonly Container $container)
    {
    }

    public function __invoke(array $args, array $assocArgs): void
    {
        $rows = array_map(static function (array $row): array {
            return [
                'version' => $row['version'],
                'status' => $row['applied'] ? ($row['checksum_ok'] ? 'APLICADA' : 'CHECKSUM_DIVERGENTE') : 'PENDENTE',
                'description' => $row['description'],
            ];
        }, $this->container->migrations()->status());

        \WP_CLI\Utils\format_items('table', $rows, ['version', 'status', 'description']);
    }
}
