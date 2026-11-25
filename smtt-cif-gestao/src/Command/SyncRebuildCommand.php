<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Command;

use SMTT\CIF\Gestao\Sync\ReconciliationService;

final class SyncRebuildCommand
{
    public function __construct(private readonly ReconciliationService $service)
    {
    }

    public function __invoke(): void
    {
        $count = $this->service->rebuildPublicQueue();
        \WP_CLI::success($count . ' projeções públicas reenfileiradas para reconstrução.');
    }
}
