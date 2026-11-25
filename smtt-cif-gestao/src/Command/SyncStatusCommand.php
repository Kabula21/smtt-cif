<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Command;

use SMTT\CIF\Gestao\Repository\SyncRepository;
use SMTT\CIF\Gestao\Service\SyncConfigService;

final class SyncStatusCommand
{
    public function __construct(private readonly SyncRepository $repository, private readonly SyncConfigService $config)
    {
    }

    public function __invoke(): void
    {
        $cfg = $this->config->overview();
        $state = $this->repository->state();
        $stats = $this->repository->queueStats();
        \WP_CLI::log('Habilitada: ' . ($cfg['enabled'] ? 'sim' : 'não'));
        \WP_CLI::log('Ambiente: ' . $cfg['environment']);
        \WP_CLI::log('Portal: ' . ($cfg['portal_base_url'] ?: '(não configurado)'));
        \WP_CLI::log('Chave ativa: ' . (($cfg['active_key']['key_id'] ?? null) ?: '(nenhuma)'));
        \WP_CLI::log('Pendentes: ' . $stats['pending'] . ' | retry: ' . $stats['retrying'] . ' | atenção: ' . $stats['attention']);
        \WP_CLI::log('Último sucesso: ' . (($state['ultimo_sucesso_em'] ?? null) ?: '-'));
        \WP_CLI::log('Último heartbeat OK: ' . (($state['ultimo_heartbeat_ok_em'] ?? null) ?: '-'));
        \WP_CLI::log('Portal plugin/schema: ' . (($state['remote_plugin_version'] ?? null) ?: '-') . ' / ' . (($state['remote_schema_version'] ?? null) ?: '-'));
    }
}
