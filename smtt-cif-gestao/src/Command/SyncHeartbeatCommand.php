<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Command;

use SMTT\CIF\Gestao\Sync\HeartbeatService;

final class SyncHeartbeatCommand
{
    public function __construct(private readonly HeartbeatService $heartbeat)
    {
    }

    public function __invoke(): void
    {
        $this->heartbeat->send();
        \WP_CLI::success('Heartbeat confirmado pelo portal público.');
    }
}
