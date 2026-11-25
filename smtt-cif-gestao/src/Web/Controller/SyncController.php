<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use RuntimeException;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Repository\SyncRepository;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Service\SyncConfigService;
use SMTT\CIF\Gestao\Sync\HeartbeatService;
use SMTT\CIF\Gestao\Sync\ReconciliationService;
use SMTT\CIF\Gestao\Sync\SyncWorker;
use SMTT\CIF\Gestao\Sync\SyncScheduler;
use SMTT\CIF\Gestao\Web\View;

final class SyncController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly SyncRepository $repository,
        private readonly SyncConfigService $config,
        private readonly SyncWorker $worker,
        private readonly HeartbeatService $heartbeat,
        private readonly ReconciliationService $reconciliation,
        private readonly SyncScheduler $scheduler
    ) {
    }

    public function index(): never
    {
        $user = $this->requireAdmin();
        $this->view->render('sync/index', [
            'title' => 'Sincronização com o portal público',
            'currentUser' => $user,
            'activeNav' => 'sync',
            'config' => $this->config->overview(),
            'state' => $this->repository->state(),
            'stats' => $this->repository->queueStats(),
            'events' => $this->repository->recentEvents(40),
            'scheduler' => $this->scheduler->status(),
            'canConfigure' => $user->can(PermissionCodes::SINCRONIZACAO_CONFIGURAR),
            'canRun' => $user->can(PermissionCodes::SINCRONIZACAO_REENVIAR),
            'canReconcile' => $user->can(PermissionCodes::SINCRONIZACAO_RECONCILIAR),
            'canRebuild' => $user->can(PermissionCodes::SINCRONIZACAO_RECONSTRUIR),
            'notice' => $this->notice(isset($_GET['notice']) ? (string)$_GET['notice'] : ''),
            'error' => isset($_GET['erro']) ? sanitize_text_field((string)wp_unslash($_GET['erro'])) : null,
        ]);
    }

    public function saveConfig(): never
    {
        $user = $this->requireAdmin();
        $this->postNonce('smtt_cif_sync_config');
        try {
            $this->config->save([
                'portal_base_url' => isset($_POST['portal_base_url']) ? (string)wp_unslash($_POST['portal_base_url']) : '',
                'environment' => isset($_POST['environment']) ? sanitize_text_field((string)wp_unslash($_POST['environment'])) : '',
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'heartbeat_interval' => isset($_POST['heartbeat_interval']) ? (int)$_POST['heartbeat_interval'] : 300,
            ], $user);
            $this->redirect('config-saved');
        } catch (\Throwable $e) {
            $this->redirectError($e);
        }
    }

    public function generateKey(): never
    {
        $user = $this->requireAdmin();
        $this->postNonce('smtt_cif_sync_key_generate');
        try {
            $this->config->generateCandidate($user);
            $this->redirect('key-generated');
        } catch (\Throwable $e) {
            $this->redirectError($e);
        }
    }

    public function activateKey(): never
    {
        $user = $this->requireAdmin();
        $this->postNonce('smtt_cif_sync_key_activate');
        $keyId = isset($_POST['key_id']) ? sanitize_text_field((string)wp_unslash($_POST['key_id'])) : '';
        try {
            if ($keyId === '') {
                throw new RuntimeException('Informe a chave que será ativada.');
            }
            $this->config->activateKey($keyId, $user);
            $this->redirect('key-activated');
        } catch (\Throwable $e) {
            $this->redirectError($e);
        }
    }

    public function syncNow(): never
    {
        $this->requireAdmin();
        $this->postNonce('smtt_cif_sync_now');
        try {
            // Fluxo operacional único: confirma o portal, escoa a fila, compara as projeções
            // e entrega imediatamente qualquer correção reenfileirada pela reconciliação.
            $this->heartbeat->send();
            $first = $this->worker->run(100, false);
            $reconciliation = $this->reconciliation->reconcile();
            $second = ['processed' => 0, 'delivered' => 0, 'retry' => 0, 'attention' => 0];
            if ((int)($reconciliation['reenqueued'] ?? 0) > 0) {
                $second = $this->worker->run(200, false);
            }

            $delivered = (int)$first['delivered'] + (int)$second['delivered'];
            $retry = (int)$first['retry'] + (int)$second['retry'];
            $attention = (int)$first['attention'] + (int)$second['attention'];
            $divergences = (int)($reconciliation['divergences'] ?? 0);
            $reenqueued = (int)($reconciliation['reenqueued'] ?? 0);
            $errors = (int)($reconciliation['errors'] ?? 0);
            $this->redirect('sync-now-' . $delivered . '-' . $retry . '-' . $attention . '-' . $divergences . '-' . $reenqueued . '-' . $errors);
        } catch (\Throwable $e) {
            $this->redirectError($e);
        }
    }

    public function run(): never
    {
        $this->requireAdmin();
        $this->postNonce('smtt_cif_sync_run');
        try {
            $result = $this->worker->run(30, true);
            $notice = 'worker-run-' . (int)$result['delivered'] . '-' . (int)$result['retry'] . '-' . (int)$result['attention'];
            $this->redirect($notice);
        } catch (\Throwable $e) {
            $this->redirectError($e);
        }
    }

    public function heartbeat(): never
    {
        $this->requireAdmin();
        $this->postNonce('smtt_cif_sync_heartbeat');
        try {
            $this->heartbeat->send();
            $this->redirect('heartbeat-ok');
        } catch (\Throwable $e) {
            $this->redirectError($e);
        }
    }

    public function reconcile(): never
    {
        $this->requireAdmin();
        $this->postNonce('smtt_cif_sync_reconcile');
        try {
            $result = $this->reconciliation->reconcile();
            $this->redirect('reconciled-' . (int)$result['divergences'] . '-' . (int)$result['reenqueued']);
        } catch (\Throwable $e) {
            $this->redirectError($e);
        }
    }

    public function rebuild(): never
    {
        $this->requireAdmin();
        $this->postNonce('smtt_cif_sync_rebuild');
        try {
            $count = $this->reconciliation->rebuildPublicQueue();
            $this->redirect('rebuilt-' . $count);
        } catch (\Throwable $e) {
            $this->redirectError($e);
        }
    }

    public function requeue(string $eventUuid): never
    {
        $this->requireAdmin();
        $this->postNonce('smtt_cif_sync_requeue_' . $eventUuid);
        try {
            $this->repository->requeue($eventUuid);
            $this->redirect('requeued');
        } catch (\Throwable $e) {
            $this->redirectError($e);
        }
    }


    private function requireAdmin(): \SMTT\CIF\Gestao\Security\CifUser
    {
        try {
            return $this->gate->requireSystemAdministrator();
        } catch (AuthorizationException $e) {
            $user = $this->gate->user();
            if ($user === null) {
                wp_safe_redirect(home_url('/cif/login'));
                exit;
            }
            $this->view->render('error/forbidden', [
                'title' => 'Acesso negado', 'message' => $e->getMessage(), 'currentUser' => $user, 'activeNav' => '',
            ], 403);
        }
    }

    private function require(string $permission): \SMTT\CIF\Gestao\Security\CifUser
    {
        try {
            return $this->gate->requirePermission($permission);
        } catch (AuthorizationException $e) {
            $user = $this->gate->user();
            if ($user === null) {
                wp_safe_redirect(home_url('/cif/login'));
                exit;
            }
            $this->view->render('error/forbidden', [
                'title' => 'Acesso negado', 'message' => $e->getMessage(), 'currentUser' => $user, 'activeNav' => '',
            ], 403);
        }
    }

    private function postNonce(string $action): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->view->render('error/not-found', ['title' => 'Página não encontrada', 'message' => 'Ação não encontrada.', 'currentUser' => $this->gate->user(), 'activeNav' => ''], 404);
        }
        $nonce = isset($_POST['_wpnonce']) ? (string)wp_unslash($_POST['_wpnonce']) : '';
        if (!\smtt_cif_verify_nonce($nonce, $action)) {
            wp_die('A solicitação não pôde ser validada.', 'SMTT CIF', ['response' => 403]);
        }
    }

    private function redirect(string $notice): never
    {
        wp_safe_redirect(add_query_arg('notice', rawurlencode($notice), home_url('/cif/sincronizacao')));
        exit;
    }

    private function redirectError(\Throwable $e): never
    {
        error_log('[SMTT CIF] Sincronização: ' . $e->getMessage());
        wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/sincronizacao')));
        exit;
    }

    /** @return array{type:string,text:string}|null */
    private function notice(string $code): ?array
    {
        if ($code === 'config-saved') return ['type' => 'success', 'text' => 'Configuração de sincronização salva.'];
        if ($code === 'key-generated') return ['type' => 'success', 'text' => 'Nova chave Ed25519 candidata gerada. Instale a chave pública no portal antes de ativá-la.'];
        if ($code === 'key-activated') return ['type' => 'success', 'text' => 'Chave Ed25519 ativada para novas requisições.'];
        if ($code === 'heartbeat-ok') return ['type' => 'success', 'text' => 'Heartbeat confirmado pelo portal público.'];
        if ($code === 'requeued') return ['type' => 'success', 'text' => 'Evento recolocado na fila.'];
        if (preg_match('/^sync-now-(\d+)-(\d+)-(\d+)-(\d+)-(\d+)-(\d+)$/', $code, $m)) {
            $type = ((int)$m[3] > 0 || (int)$m[6] > 0) ? 'warning' : 'success';
            return ['type' => $type, 'text' => "Sincronização concluída: portal verificado; {$m[1]} entrega(s), {$m[2]} em retry, {$m[3]} requerendo atenção; {$m[4]} divergência(s), {$m[5]} correção(ões) reenfileirada(s) e {$m[6]} aviso(s) de reconciliação."];
        }
        if (preg_match('/^worker-run-(\d+)-(\d+)-(\d+)$/', $code, $m)) return ['type' => 'success', 'text' => "Worker executado: {$m[1]} entregues, {$m[2]} em retry e {$m[3]} requerendo atenção."];
        if (preg_match('/^reconciled-(\d+)-(\d+)$/', $code, $m)) return ['type' => 'success', 'text' => "Reconciliação concluída: {$m[1]} divergências; {$m[2]} projeções reenfileiradas."];
        if (preg_match('/^rebuilt-(\d+)$/', $code, $m)) return ['type' => 'success', 'text' => "Reconstrução solicitada: {$m[1]} projeções foram reenfileiradas."];
        return null;
    }
}
