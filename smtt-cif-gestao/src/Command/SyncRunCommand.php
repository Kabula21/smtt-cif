<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Command;

use SMTT\CIF\Gestao\Sync\SyncWorker;

final class SyncRunCommand
{
    public function __construct(private readonly SyncWorker $worker)
    {
    }

    /** @param list<string> $args @param array<string,mixed> $assocArgs */
    public function __invoke(array $args, array $assocArgs): void
    {
        $limit = isset($assocArgs['limit']) ? (int)$assocArgs['limit'] : 50;
        $heartbeat = !empty($assocArgs['heartbeat']);
        $result = $this->worker->run($limit, $heartbeat);
        \WP_CLI::success(sprintf(
            'Worker concluído. Processados: %d; entregues: %d; retry: %d; atenção: %d.',
            $result['processed'], $result['delivered'], $result['retry'], $result['attention']
        ));
    }
}
