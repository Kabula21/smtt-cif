<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use RuntimeException;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessStatus;
use SMTT\CIF\Gestao\Repository\AgentRepository;
use SMTT\CIF\Gestao\Repository\IssuanceProcessRepository;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Service\IssuanceProcessService;
use SMTT\CIF\Gestao\Web\View;

final class IssuanceProcessController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly IssuanceProcessRepository $repository,
        private readonly AgentRepository $agents,
        private readonly IssuanceProcessService $service
    ) {
    }

    public function index(): never
    {
        $user = $this->require(PermissionCodes::PROCESSO_VISUALIZAR);
        $filters = $this->filters();
        $page = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
        $perPage = isset($_GET['por_pagina']) ? (int) $_GET['por_pagina'] : 25;
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $result = $this->repository->search($filters, $page, $perPage);
        $pages = max(1, (int) ceil($result['total'] / $perPage));
        if ($page > $pages && $result['total'] > 0) {
            $page = $pages;
            $result = $this->repository->search($filters, $page, $perPage);
        }

        $items = [];
        foreach ($result['items'] as $item) {
            $item['tipo_label'] = $this->service->typeOptions()[(string) $item['tipo_codigo']] ?? (string) $item['tipo_codigo'];
            $item['status_label'] = $this->service->statusOptions()[(string) $item['status_codigo']] ?? (string) $item['status_codigo'];
            $items[] = $item;
        }

        $this->view->render('processes/index', [
            'title' => 'Processos de emissão',
            'currentUser' => $user,
            'activeNav' => 'processes',
            'items' => $items,
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'typeOptions' => $this->service->typeOptions(),
            'statusOptions' => $this->service->statusOptions(),
            'canCreate' => $user->can(PermissionCodes::PROCESSO_CRIAR),
            'notice' => $this->noticeMessage(isset($_GET['notice']) ? (string) $_GET['notice'] : ''),
        ]);
    }

    public function create(): never
    {
        $user = $this->require(PermissionCodes::PROCESSO_CRIAR);
        $agentUuid = isset($_GET['agente']) ? sanitize_text_field((string) wp_unslash($_GET['agente'])) : '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->verifyNonce('smtt_cif_process_create');
            $agentUuid = isset($_POST['agente_uuid']) ? sanitize_text_field((string) wp_unslash($_POST['agente_uuid'])) : '';
            try {
                $process = $this->service->create($agentUuid, $this->postedProcessData(), $user);
                wp_safe_redirect(add_query_arg('notice', 'created', home_url('/cif/processos/' . rawurlencode((string) $process['uuid']))));
                exit;
            } catch (\InvalidArgumentException|RuntimeException $e) {
                $this->renderCreate($user, $agentUuid, $this->postedProcessData(), $e->getMessage(), 422);
            } catch (\Throwable $e) {
                error_log('[SMTT CIF] Falha ao criar processo: ' . $e->getMessage());
                $this->renderCreate($user, $agentUuid, $this->postedProcessData(), 'Não foi possível criar o processo. Consulte o log técnico.', 500);
            }
        }
        $this->renderCreate($user, $agentUuid, $this->prefilledProcessForm(), null);
    }

    public function show(string $uuid): never
    {
        $user = $this->require(PermissionCodes::PROCESSO_VISUALIZAR);
        try {
            $process = $this->service->details($uuid, $user->can(PermissionCodes::AGENTE_VISUALIZAR_DADOS_SENSIVEIS));
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        }

        $this->view->render('processes/show', [
            'title' => 'Processo de emissão',
            'currentUser' => $user,
            'activeNav' => 'processes',
            'process' => $process,
            'documentTypeOptions' => $this->service->documentTypeOptions(),
            'validationMethodOptions' => $this->service->validationMethodOptions(),
            'pendingReasonOptions' => $this->service->pendingReasonOptions(),
            'denialReasonOptions' => $this->service->denialReasonOptions(),
            'cancellationReasonOptions' => $this->service->cancellationReasonOptions(),
            'canEdit' => $user->can(PermissionCodes::PROCESSO_EDITAR),
            'canValidate' => $user->can(PermissionCodes::PROCESSO_VALIDAR_DADOS),
            'canAttach' => $user->can(PermissionCodes::PROCESSO_ANEXAR_DOCUMENTOS),
            'canApprove' => $user->can(PermissionCodes::PROCESSO_APROVAR),
            'canCancel' => $user->can(PermissionCodes::PROCESSO_CANCELAR),
            'canGenerateEmission' => $user->can(PermissionCodes::EMISSAO_GERAR),
            'canViewBiometry' => $user->can(PermissionCodes::BIOMETRIA_VISUALIZAR),
            'canViewSensitiveAgentData' => $user->can(PermissionCodes::AGENTE_VISUALIZAR_DADOS_SENSIVEIS),
            'notice' => $this->noticeMessage(isset($_GET['notice']) ? (string) $_GET['notice'] : ''),
            'error' => isset($_GET['erro']) ? sanitize_text_field((string) wp_unslash($_GET['erro'])) : null,
        ]);
    }

    public function edit(string $uuid): never
    {
        $user = $this->require(PermissionCodes::PROCESSO_EDITAR);
        try {
            $process = $this->service->details($uuid);
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->verifyNonce('smtt_cif_process_edit_' . $uuid);
            $lockVersion = isset($_POST['lock_version']) ? (int) $_POST['lock_version'] : -1;
            try {
                $updated = $this->service->update($uuid, $this->postedProcessData(), $lockVersion, $user);
                wp_safe_redirect(add_query_arg('notice', 'updated', home_url('/cif/processos/' . rawurlencode((string) $updated['uuid']))));
                exit;
            } catch (\InvalidArgumentException|RuntimeException $e) {
                $this->renderEdit($user, $process, $this->postedProcessData(), $e->getMessage(), 422);
            } catch (\Throwable $e) {
                error_log('[SMTT CIF] Falha ao editar processo: ' . $e->getMessage());
                $this->renderEdit($user, $process, $this->postedProcessData(), 'Não foi possível atualizar o processo. Consulte o log técnico.', 500);
            }
        }

        $this->renderEdit($user, $process, $this->formFromProcess($process), null);
    }

    public function transition(string $uuid): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $target = isset($_POST['target_status']) ? strtoupper(sanitize_text_field((string) wp_unslash($_POST['target_status']))) : '';
        $permission = match ($target) {
            ProcessStatus::EM_ANALISE, ProcessStatus::PENDENTE_DOCUMENTACAO => PermissionCodes::PROCESSO_EDITAR,
            ProcessStatus::APROVADO, ProcessStatus::INDEFERIDO => PermissionCodes::PROCESSO_APROVAR,
            ProcessStatus::CANCELADO => PermissionCodes::PROCESSO_CANCELAR,
            default => '',
        };
        if ($permission === '') {
            $this->forbidden('Essa transição não está disponível pela interface atual.');
        }
        $user = $this->require($permission);
        $this->verifyNonce('smtt_cif_process_transition_' . $uuid);
        $reason = isset($_POST['justificativa_transicao']) ? sanitize_textarea_field((string) wp_unslash($_POST['justificativa_transicao'])) : '';
        $lockVersion = isset($_POST['lock_version']) ? (int) $_POST['lock_version'] : -1;
        try {
            $approvalValidation = [];
            if ($target === ProcessStatus::APROVADO) {
                $approvalValidation = [
                    'confirmed' => isset($_POST['titular_confirmou']) && (string) $_POST['titular_confirmou'] === '1',
                    'method' => isset($_POST['metodo_validacao']) ? sanitize_text_field((string) wp_unslash($_POST['metodo_validacao'])) : '',
                    'observations' => isset($_POST['observacoes_validacao']) ? sanitize_textarea_field((string) wp_unslash($_POST['observacoes_validacao'])) : '',
                ];
            }
            $transitionContext = [
                'reason_code' => isset($_POST['motivo_codigo']) ? sanitize_text_field((string) wp_unslash($_POST['motivo_codigo'])) : '',
                'deadline' => isset($_POST['prazo_regularizacao']) ? sanitize_text_field((string) wp_unslash($_POST['prazo_regularizacao'])) : '',
            ];
            $this->service->transition($uuid, $target, $reason, $lockVersion, $user, $approvalValidation, $transitionContext);
            wp_safe_redirect(add_query_arg('notice', strtolower($target), home_url('/cif/processos/' . rawurlencode($uuid))));
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/processos/' . rawurlencode($uuid))));
            exit;
        }
    }

    public function validateBiography(string $uuid): never
    {
        $user = $this->require(PermissionCodes::PROCESSO_VALIDAR_DADOS);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $this->verifyNonce('smtt_cif_process_validate_' . $uuid);
        $confirmed = isset($_POST['titular_confirmou']) && (string) $_POST['titular_confirmou'] === '1';
        if (!$confirmed) {
            wp_safe_redirect(add_query_arg('erro', 'Confirme que os dados apresentados foram conferidos com o titular.', home_url('/cif/processos/' . rawurlencode($uuid))));
            exit;
        }
        try {
            $this->service->validateBiography(
                $uuid,
                isset($_POST['metodo']) ? sanitize_text_field((string) wp_unslash($_POST['metodo'])) : '',
                isset($_POST['observacoes']) ? sanitize_textarea_field((string) wp_unslash($_POST['observacoes'])) : '',
                $user
            );
            wp_safe_redirect(add_query_arg('notice', 'validated', home_url('/cif/processos/' . rawurlencode($uuid))));
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/processos/' . rawurlencode($uuid))));
            exit;
        }
    }

    public function addDocument(string $uuid): never
    {
        $user = $this->require(PermissionCodes::PROCESSO_ANEXAR_DOCUMENTOS);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $this->verifyNonce('smtt_cif_process_document_' . $uuid);
        try {
            $this->service->addDocument(
                $uuid,
                isset($_FILES['documento']) && is_array($_FILES['documento']) ? $_FILES['documento'] : [],
                isset($_POST['tipo_documento']) ? sanitize_text_field((string) wp_unslash($_POST['tipo_documento'])) : '',
                isset($_POST['numero_documento']) ? sanitize_text_field((string) wp_unslash($_POST['numero_documento'])) : '',
                isset($_POST['emitido_em']) ? sanitize_text_field((string) wp_unslash($_POST['emitido_em'])) : '',
                $user
            );
            wp_safe_redirect(add_query_arg('notice', 'document-added', home_url('/cif/processos/' . rawurlencode($uuid))));
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/processos/' . rawurlencode($uuid))));
            exit;
        }
    }

    public function file(string $fileUuid): never
    {
        $this->require(PermissionCodes::PROCESSO_VISUALIZAR);
        try {
            $file = $this->service->privateDocument($fileUuid);
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        }
        nocache_headers();
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . (string) $file['size']);
        header('Content-Disposition: inline; filename="' . rawurlencode($file['name']) . '"');
        echo $file['bytes'];
        exit;
    }

    private function renderCreate(\SMTT\CIF\Gestao\Security\CifUser $user, string $agentUuid, array $form, ?string $error, int $status = 200): never
    {
        $agent = null;
        if ($agentUuid !== '') {
            try {
                $agent = $this->service->agentForCreation($agentUuid);
            } catch (RuntimeException) {
                $error = $error ?? 'Agente não localizado.';
            }
        }
        $q = isset($_GET['q']) ? sanitize_text_field((string) wp_unslash($_GET['q'])) : '';
        $candidates = [];
        if ($agent === null) {
            $search = $this->agents->search(['q' => $q, 'ordenar' => 'nome', 'direcao' => 'ASC'], 1, 25);
            $candidates = $search['items'];
        }
        $this->view->render('processes/form', [
            'title' => 'Novo processo de emissão',
            'currentUser' => $user,
            'activeNav' => 'processes',
            'mode' => 'create',
            'agent' => $agent,
            'agentUuid' => $agentUuid,
            'candidates' => $candidates,
            'searchQuery' => $q,
            'process' => null,
            'form' => $form,
            'error' => $error,
            'typeOptions' => $this->service->typeOptions(),
        ], $status);
    }

    private function renderEdit(\SMTT\CIF\Gestao\Security\CifUser $user, array $process, array $form, ?string $error, int $status = 200): never
    {
        $this->view->render('processes/form', [
            'title' => 'Editar processo de emissão',
            'currentUser' => $user,
            'activeNav' => 'processes',
            'mode' => 'edit',
            'agent' => $process,
            'agentUuid' => (string) $process['agente_uuid'],
            'candidates' => [],
            'searchQuery' => '',
            'process' => $process,
            'form' => $form,
            'error' => $error,
            'typeOptions' => $this->service->typeOptions(),
        ], $status);
    }

    /** @return array<string,string> */
    private function postedProcessData(): array
    {
        $keys = ['tipo_codigo', 'protocolo_externo', 'justificativa', 'fato_em', 'comunicado_em', 'occurrence_uuid'];
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = isset($_POST[$key]) ? sanitize_textarea_field((string) wp_unslash($_POST[$key])) : '';
        }
        return $data;
    }

    /** @return array<string,string> */
    private function emptyProcessForm(): array
    {
        return ['tipo_codigo' => 'PRIMEIRA_EMISSAO', 'protocolo_externo' => '', 'justificativa' => '', 'fato_em' => '', 'comunicado_em' => '', 'occurrence_uuid' => ''];
    }

    /** @return array<string,string> */
    private function prefilledProcessForm(): array
    {
        $form = $this->emptyProcessForm();
        $type = isset($_GET['tipo']) ? strtoupper(sanitize_text_field((string) wp_unslash($_GET['tipo']))) : '';
        if ($type !== '' && array_key_exists($type, $this->service->typeOptions())) {
            $form['tipo_codigo'] = $type;
        }
        $fact = isset($_GET['fato']) ? sanitize_text_field((string) wp_unslash($_GET['fato'])) : '';
        $communicated = isset($_GET['comunicado']) ? sanitize_text_field((string) wp_unslash($_GET['comunicado'])) : '';
        $occurrenceUuid = isset($_GET['ocorrencia']) ? sanitize_text_field((string) wp_unslash($_GET['ocorrencia'])) : '';
        if ($occurrenceUuid !== '') {
            $form['occurrence_uuid'] = $occurrenceUuid;
        }
        if ($fact !== '') {
            $form['fato_em'] = $this->utcForInput($fact);
        }
        if ($communicated !== '') {
            $form['comunicado_em'] = $this->utcForInput($communicated);
        }
        return $form;
    }

    /** @param array<string,mixed> $process @return array<string,string> */
    private function formFromProcess(array $process): array
    {
        return [
            'tipo_codigo' => (string) $process['tipo_codigo'],
            'protocolo_externo' => (string) ($process['protocolo_externo'] ?? ''),
            'justificativa' => (string) ($process['justificativa'] ?? ''),
            'fato_em' => $this->utcForInput($process['fato_em'] ?? null),
            'comunicado_em' => $this->utcForInput($process['comunicado_em'] ?? null),
        ];
    }

    private function utcForInput(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        try {
            $utc = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            return $utc->setTimezone($tz)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        $get = static fn(string $key): string => isset($_GET[$key]) ? sanitize_text_field((string) wp_unslash($_GET[$key])) : '';
        $type = strtoupper(trim($get('tipo')));
        $status = strtoupper(trim($get('status')));
        return [
            'q' => trim($get('q')),
            'tipo' => array_key_exists($type, $this->service->typeOptions()) ? $type : '',
            'status' => array_key_exists($status, $this->service->statusOptions()) ? $status : '',
            'criado' => $this->dateFilter($get('criado')),
            'ordenar' => in_array($get('ordenar'), ['criado', 'nome', 'matricula', 'status', 'tipo', 'protocolo'], true) ? $get('ordenar') : 'criado',
            'direcao' => strtoupper($get('direcao')) === 'ASC' ? 'ASC' : 'DESC',
        ];
    }

    private function dateFilter(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function verifyNonce(string $action): void
    {
        $nonce = isset($_POST['smtt_cif_nonce']) ? (string) wp_unslash($_POST['smtt_cif_nonce']) : '';
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
    private function noticeMessage(string $code): ?array
    {
        return match ($code) {
            'created' => ['type' => 'success', 'text' => 'Processo criado e colocado em tramitação.'],
            'updated' => ['type' => 'success', 'text' => 'Processo atualizado.'],
            'validated' => ['type' => 'success', 'text' => 'Validação da versão biográfica atual registrada.'],
            'document-added' => ['type' => 'success', 'text' => 'Documento anexado ao processo com armazenamento privado criptografado.'],
            'em_analise' => ['type' => 'success', 'text' => 'Processo em tramitação.'],
            'pendente_documentacao' => ['type' => 'success', 'text' => 'Pendência documental registrada.'],
            'aprovado' => ['type' => 'success', 'text' => 'Processo aprovado e liberado para geração da CIF.'],
            'indeferido' => ['type' => 'success', 'text' => 'Processo indeferido e encerrado.'],
            'cancelado' => ['type' => 'success', 'text' => 'Processo cancelado.'],
            default => null,
        };
    }

    private function forbidden(string $message): never
    {
        $this->view->render('error/forbidden', ['title' => 'Acesso negado', 'message' => $message, 'currentUser' => $this->gate->user()], 403);
    }

    private function notFound(string $message): never
    {
        $this->view->render('error/not-found', ['title' => 'Processo não encontrado', 'message' => $message, 'currentUser' => $this->gate->user()], 404);
    }
}
