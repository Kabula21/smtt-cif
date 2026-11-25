<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Command;

use SMTT\CIF\Gestao\Sync\ReconciliationService;

final class SyncReconcileCommand
{
    public function __construct(private readonly ReconciliationService $service)
    {
    }

    public function __invoke(): void
    {
        $result = $this->service->reconcile();
        \WP_CLI::success(sprintf('Reconciliação: %d verificados, %d divergências, %d reenfileirados, %d erros.', $result['checked'], $result['divergences'], $result['reenqueued'], $result['errors']));
    }
}
