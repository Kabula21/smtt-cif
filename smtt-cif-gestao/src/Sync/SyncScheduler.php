<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Sync;

use Closure;
use SMTT\CIF\Gestao\Repository\SyncRepository;
use SMTT\CIF\Gestao\Service\SyncConfigService;

final class SyncScheduler
{
    public const QUEUE_HOOK = 'smtt_cif_sync_queue_tick';
    public const RECONCILE_HOOK = 'smtt_cif_sync_reconcile_tick';
    public const QUEUE_SCHEDULE = 'smtt_cif_every_minute';
    public const RECONCILE_SCHEDULE = 'smtt_cif_every_fifteen_minutes';

    private const BACKGROUND_LOCK = 'smtt_cif_sync_background';

    /**
     * As dependências são fábricas para que registrar o cron não abra o banco smtt_cif
     * em páginas WordPress que não pertencem ao Sistema CIF. O banco só é resolvido
     * quando um tick do cron realmente executa trabalho.
     *
     * @param Closure():SyncRepository $repositoryFactory
     * @param Closure():SyncConfigService $configFactory
     * @param Closure():SyncWorker $workerFactory
     * @param Closure():ReconciliationService $reconciliationFactory
     */
    public function __construct(
        private readonly Closure $repositoryFactory,
        private readonly Closure $configFactory,
        private readonly Closure $workerFactory,
        private readonly Closure $reconciliationFactory
    ) {
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'cronSchedules']);
        add_action(self::QUEUE_HOOK, [$this, 'runQueue']);
        add_action(self::RECONCILE_HOOK, [$this, 'runReconciliation']);
    }

    /** @param array<string,array<string,mixed>> $schedules @return array<string,array<string,mixed>> */
    public function cronSchedules(array $schedules): array
    {
        $schedules[self::QUEUE_SCHEDULE] = [
            'interval' => 60,
            'display' => 'SMTT CIF — a cada minuto',
        ];
        $schedules[self::RECONCILE_SCHEDULE] = [
            'interval' => 15 * 60,
            'display' => 'SMTT CIF — a cada quinze minutos',
        ];
        return $schedules;
    }

    public function ensureScheduled(): void
    {
        if (!wp_next_scheduled(self::QUEUE_HOOK)) {
            wp_schedule_event(time() + 60, self::QUEUE_SCHEDULE, self::QUEUE_HOOK);
        }
        if (!wp_next_scheduled(self::RECONCILE_HOOK)) {
            wp_schedule_event(time() + (5 * 60), self::RECONCILE_SCHEDULE, self::RECONCILE_HOOK);
        }
    }

    public static function clearScheduled(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::QUEUE_HOOK);
            wp_clear_scheduled_hook(self::RECONCILE_HOOK);
        }
    }

    /** @return array{queue_scheduled:bool,reconcile_scheduled:bool,next_queue:?int,next_reconcile:?int} */
    public function status(): array
    {
        $queue = wp_next_scheduled(self::QUEUE_HOOK);
        $reconcile = wp_next_scheduled(self::RECONCILE_HOOK);
        return [
            'queue_scheduled' => is_int($queue) && $queue > 0,
            'reconcile_scheduled' => is_int($reconcile) && $reconcile > 0,
            'next_queue' => is_int($queue) ? $queue : null,
            'next_reconcile' => is_int($reconcile) ? $reconcile : null,
        ];
    }

    public function runQueue(): void
    {
        $config = $this->config();
        if (!$config->enabled()) {
            return;
        }
        $repository = $this->repository();
        if (!$repository->acquireAutomationLock(self::BACKGROUND_LOCK)) {
            return;
        }
        try {
            // Pequenos lotes mantêm o trabalho de rede fora das ações do usuário e
            // evitam que uma execução automática monopolize o processo PHP.
            $this->worker()->run(25, false);
            $repository->recordAutomaticWorker(true, null);
        } catch (\Throwable $e) {
            $message = $this->truncate($e->getMessage());
            $repository->recordAutomaticWorker(false, $message);
            error_log('[SMTT CIF] Worker automático de sincronização: ' . $message);
        } finally {
            $repository->releaseAutomationLock(self::BACKGROUND_LOCK);
        }
    }

    public function runReconciliation(): void
    {
        $config = $this->config();
        if (!$config->enabled()) {
            return;
        }
        $repository = $this->repository();
        if (!$repository->acquireAutomationLock(self::BACKGROUND_LOCK)) {
            return;
        }
        try {
            // A reconciliação automática é fatiada. Cada ciclo verifica no máximo
            // 200 emissões e mantém um cursor, evitando varrer toda a base a cada 15 minutos.
            $result = $this->reconciliation()->reconcileAutomaticSlice(200);
            if ((int)($result['reenqueued'] ?? 0) > 0) {
                $this->worker()->run(50, false);
            }
            $repository->recordAutomaticReconciliation(true, null);
        } catch (\Throwable $e) {
            $message = $this->truncate($e->getMessage());
            $repository->recordAutomaticReconciliation(false, $message);
            error_log('[SMTT CIF] Reconciliação automática: ' . $message);
        } finally {
            $repository->releaseAutomationLock(self::BACKGROUND_LOCK);
        }
    }

    private function repository(): SyncRepository
    {
        return ($this->repositoryFactory)();
    }

    private function config(): SyncConfigService
    {
        return ($this->configFactory)();
    }

    private function worker(): SyncWorker
    {
        return ($this->workerFactory)();
    }

    private function reconciliation(): ReconciliationService
    {
        return ($this->reconciliationFactory)();
    }

    private function truncate(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        return strlen($message) > 450 ? substr($message, 0, 447) . '...' : $message;
    }
}
