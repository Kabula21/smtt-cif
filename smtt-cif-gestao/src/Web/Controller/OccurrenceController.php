<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use RuntimeException;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Repository\EmissionRepository;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Service\OccurrenceService;
use SMTT\CIF\Gestao\Web\View;

final class OccurrenceController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly EmissionRepository $emissions,
        private readonly OccurrenceService $service
    ) {
    }

    public function index(): never
    {
        $this->require(PermissionCodes::OCORRENCIA_VISUALIZAR);
        wp_safe_redirect(add_query_arg('tipo', 'ocorrencias', home_url('/cif/relatorios')));
        exit;
    }

    public function show(string $uuid): never
    {
        $user = $this->require(PermissionCodes::OCORRENCIA_VISUALIZAR);
        try {
            $occurrence = $this->service->occurrence($uuid);
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        }
        $this->view->render('occurrences/show', [
            'title' => 'Ocorrência CIF',
            'currentUser' => $user,
            'activeNav' => 'reports',
            'occurrence' => $occurrence,
            'canRecover' => $user->can(PermissionCodes::OCORRENCIA_RECUPERAR),
            'notice' => $this->notice(isset($_GET['notice']) ? (string)$_GET['notice'] : ''),
            'error' => isset($_GET['erro']) ? sanitize_text_field((string)wp_unslash($_GET['erro'])) : null,
        ]);
    }

    public function create(string $emissionUuid): never
    {
        $user = $this->require(PermissionCodes::OCORRENCIA_REGISTRAR);
        $emission = $this->emissions->detailsByUuid($emissionUuid);
        if ($emission === null) {
            $this->notFound('Emissão CIF não localizada.');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->verifyNonce('smtt_cif_occurrence_create_' . $emissionUuid);
            try {
                $occurrence = $this->service->registerOccurrence(
                    $emissionUuid,
                    [
                        'tipo_codigo' => isset($_POST['tipo_codigo']) ? sanitize_text_field((string)wp_unslash($_POST['tipo_codigo'])) : '',
                        'data_fato' => isset($_POST['data_fato']) ? sanitize_text_field((string)wp_unslash($_POST['data_fato'])) : '',
                        'data_comunicacao' => isset($_POST['data_comunicacao']) ? sanitize_text_field((string)wp_unslash($_POST['data_comunicacao'])) : '',
                        'bo_numero' => isset($_POST['bo_numero']) ? sanitize_text_field((string)wp_unslash($_POST['bo_numero'])) : '',
                        'bo_data' => isset($_POST['bo_data']) ? sanitize_text_field((string)wp_unslash($_POST['bo_data'])) : '',
                        'bo_orgao' => isset($_POST['bo_orgao']) ? sanitize_text_field((string)wp_unslash($_POST['bo_orgao'])) : '',
                        'detalhes' => isset($_POST['detalhes']) ? sanitize_textarea_field((string)wp_unslash($_POST['detalhes'])) : '',
                    ],
                    isset($_FILES['documento']) && is_array($_FILES['documento']) ? $_FILES['documento'] : [],
                    $user
                );
                wp_safe_redirect(add_query_arg('notice', 'occurrence-created', home_url('/cif/emissoes/' . rawurlencode($emissionUuid))) . '#historico-cif');
                exit;
            } catch (\InvalidArgumentException|RuntimeException $e) {
                $this->renderCreate($user, $emission, $e->getMessage(), 422);
            } catch (\Throwable $e) {
                error_log('[SMTT CIF] Falha ao registrar ocorrência: ' . $e->getMessage());
                $this->renderCreate($user, $emission, 'Não foi possível registrar a ocorrência. Consulte o log técnico.', 500);
            }
        }
        $this->renderCreate($user, $emission, null);
    }

    public function recover(string $uuid): never
    {
        $user = $this->require(PermissionCodes::OCORRENCIA_RECUPERAR);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $this->verifyNonce('smtt_cif_occurrence_recover_' . $uuid);
        try {
            $occurrence = $this->service->recover($uuid, $user);
            wp_safe_redirect(add_query_arg('notice', 'occurrence-recovered', home_url('/cif/emissoes/' . rawurlencode((string)$occurrence['emissao_uuid']))) . '#historico-cif');
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/ocorrencias/' . rawurlencode($uuid))));
            exit;
        }
    }

    public function file(string $uuid): never
    {
        $this->require(PermissionCodes::OCORRENCIA_VISUALIZAR);
        try {
            $file = $this->service->privateDocument($uuid);
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        }
        nocache_headers();
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . (string)$file['size']);
        header('Content-Disposition: inline; filename="' . sanitize_file_name($file['name']) . '"');
        echo $file['bytes'];
        exit;
    }

    public function recollections(): never
    {
        $this->require(PermissionCodes::RECOLHIMENTO_VISUALIZAR);
        wp_safe_redirect(add_query_arg('tipo', 'recolhimentos', home_url('/cif/relatorios')));
        exit;
    }

    public function recollectionShow(string $uuid): never
    {
        $user = $this->require(PermissionCodes::RECOLHIMENTO_VISUALIZAR);
        try {
            $recollection = $this->service->recollection($uuid);
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        }
        $this->view->render('occurrences/recollection-show', [
            'title' => 'Recolhimento CIF',
            'currentUser' => $user,
            'activeNav' => 'reports',
            'recollection' => $recollection,
            'canRegister' => $user->can(PermissionCodes::RECOLHIMENTO_REGISTRAR),
            'canInvalidate' => $user->can(PermissionCodes::RECOLHIMENTO_INUTILIZAR),
            'notice' => $this->notice(isset($_GET['notice']) ? (string)$_GET['notice'] : ''),
            'error' => isset($_GET['erro']) ? sanitize_text_field((string)wp_unslash($_GET['erro'])) : null,
        ]);
    }

    public function createRecollection(string $emissionUuid): never
    {
        $user = $this->require(PermissionCodes::RECOLHIMENTO_REGISTRAR);
        $emission = $this->emissions->detailsByUuid($emissionUuid);
        if ($emission === null) {
            $this->notFound('Emissão CIF não localizada.');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->verifyNonce('smtt_cif_recollection_create_' . $emissionUuid);
            try {
                $recollection = $this->service->registerAdministrativeRecollection($emissionUuid, [
                    'motivo_codigo' => isset($_POST['motivo_codigo']) ? sanitize_text_field((string)wp_unslash($_POST['motivo_codigo'])) : '',
                    'fato_em' => isset($_POST['fato_em']) ? sanitize_text_field((string)wp_unslash($_POST['fato_em'])) : '',
                    'referencia' => isset($_POST['referencia']) ? sanitize_text_field((string)wp_unslash($_POST['referencia'])) : '',
                    'observacoes' => isset($_POST['observacoes']) ? sanitize_textarea_field((string)wp_unslash($_POST['observacoes'])) : '',
                    'recolhida_agora' => isset($_POST['recolhida_agora']) ? 1 : 0,
                ], $user);
                wp_safe_redirect(add_query_arg('notice', 'recollection-created', home_url('/cif/emissoes/' . rawurlencode($emissionUuid))) . '#historico-cif');
                exit;
            } catch (\InvalidArgumentException|RuntimeException $e) {
                $this->renderRecollectionCreate($user, $emission, $e->getMessage(), 422);
            }
        }
        $this->renderRecollectionCreate($user, $emission, null);
    }

    public function collect(string $uuid): never
    {
        $user = $this->require(PermissionCodes::RECOLHIMENTO_REGISTRAR);
        $this->postOnly('smtt_cif_recollection_collect_' . $uuid);
        try {
            $this->service->collectPending($uuid, [
                'referencia' => isset($_POST['referencia']) ? sanitize_text_field((string)wp_unslash($_POST['referencia'])) : '',
                'observacoes' => isset($_POST['observacoes']) ? sanitize_textarea_field((string)wp_unslash($_POST['observacoes'])) : '',
            ], $user);
            $recollection = $this->service->recollection($uuid);
            wp_safe_redirect(add_query_arg('notice', 'recollection-collected', home_url('/cif/emissoes/' . rawurlencode((string)$recollection['emissao_uuid']))) . '#historico-cif');
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/recolhimentos/' . rawurlencode($uuid))));
            exit;
        }
    }

    public function notCollected(string $uuid): never
    {
        $user = $this->require(PermissionCodes::RECOLHIMENTO_REGISTRAR);
        $this->postOnly('smtt_cif_recollection_not_collected_' . $uuid);
        $reason = isset($_POST['justificativa']) ? sanitize_textarea_field((string)wp_unslash($_POST['justificativa'])) : '';
        try {
            $this->service->justifyNotCollected($uuid, $reason, $user);
            $recollection = $this->service->recollection($uuid);
            wp_safe_redirect(add_query_arg('notice', 'recollection-not-collected', home_url('/cif/emissoes/' . rawurlencode((string)$recollection['emissao_uuid']))) . '#historico-cif');
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/recolhimentos/' . rawurlencode($uuid))));
            exit;
        }
    }

    public function invalidate(string $uuid): never
    {
        $user = $this->require(PermissionCodes::RECOLHIMENTO_INUTILIZAR);
        $this->postOnly('smtt_cif_recollection_invalidate_' . $uuid);
        $observations = isset($_POST['observacoes']) ? sanitize_textarea_field((string)wp_unslash($_POST['observacoes'])) : '';
        try {
            $this->service->invalidate($uuid, $observations, $user);
            $recollection = $this->service->recollection($uuid);
            wp_safe_redirect(add_query_arg('notice', 'recollection-invalidated', home_url('/cif/emissoes/' . rawurlencode((string)$recollection['emissao_uuid']))) . '#historico-cif');
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/recolhimentos/' . rawurlencode($uuid))));
            exit;
        }
    }

    private function dateFilter(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function renderCreate($user, array $emission, ?string $error, int $status = 200): never
    {
        $this->view->render('occurrences/form', [
            'title' => 'Registrar ocorrência', 'currentUser' => $user, 'activeNav' => 'emissions',
            'emission' => $emission, 'typeOptions' => $this->service->occurrenceTypes(), 'error' => $error,
        ], $status);
    }

    private function renderRecollectionCreate($user, array $emission, ?string $error, int $status = 200): never
    {
        $this->view->render('occurrences/recollection-form', [
            'title' => 'Registrar recolhimento', 'currentUser' => $user, 'activeNav' => 'emissions',
            'emission' => $emission, 'reasonOptions' => $this->service->recollectionReasons(), 'error' => $error,
        ], $status);
    }

    private function postOnly(string $nonceAction): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $this->verifyNonce($nonceAction);
    }

    private function verifyNonce(string $action): void
    {
        $nonce = isset($_POST['smtt_cif_nonce']) ? (string)wp_unslash($_POST['smtt_cif_nonce']) : '';
        if (!\smtt_cif_verify_nonce($nonce, $action)) {
            $this->forbidden('A sessão do formulário expirou. Atualize a página e tente novamente.');
        }
    }

    private function require(string $permission): \SMTT\CIF\Gestao\Security\CifUser
    {
        try {
            return $this->gate->requirePermission($permission);
        } catch (AuthorizationException $e) {
            if ($this->gate->user() === null) {
                wp_safe_redirect(home_url('/cif/login'));
                exit;
            }
            $this->forbidden($e->getMessage());
        }
    }

    /** @return array{type:string,text:string}|null */
    private function notice(string $code): ?array
    {
        return match ($code) {
            'created' => ['type' => 'success', 'text' => 'Ocorrência registrada com sucesso.'],
            'recovered' => ['type' => 'success', 'text' => 'Recuperação da CIF registrada e estado atualizado conforme o histórico da emissão.'],
            'recollection-created' => ['type' => 'success', 'text' => 'Recolhimento administrativo registrado.'],
            'collected' => ['type' => 'success', 'text' => 'Recolhimento físico confirmado.'],
            'not-collected' => ['type' => 'warning', 'text' => 'Impossibilidade de recolhimento registrada com justificativa.'],
            'invalidated' => ['type' => 'success', 'text' => 'Inutilização física registrada.'],
            default => null,
        };
    }

    private function forbidden(string $message): never
    {
        $this->view->render('error/forbidden', ['title' => 'Acesso negado', 'message' => $message, 'currentUser' => $this->gate->user()], 403);
    }

    private function notFound(string $message): never
    {
        $this->view->render('error/not-found', ['title' => 'Registro não encontrado', 'message' => $message, 'currentUser' => $this->gate->user()], 404);
    }
}
