<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use RuntimeException;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Repository\AgentRepository;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Service\AgentService;
use SMTT\CIF\Gestao\Service\AgentExportService;
use SMTT\CIF\Gestao\Service\BiometricService;
use SMTT\CIF\Gestao\Web\View;

final class AgentController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly AgentRepository $repository,
        private readonly AgentService $service,
        private readonly AgentExportService $exportService,
        private readonly BiometricService $biometricService
    ) {
    }

    public function index(): never
    {
        $user = $this->require(PermissionCodes::AGENTE_VISUALIZAR);
        $canSensitive = $user->can(PermissionCodes::AGENTE_VISUALIZAR_DADOS_SENSIVEIS);
        try {
            $filters = $this->filtersFromQuery($canSensitive);
            $filterError = null;
        } catch (\InvalidArgumentException $e) {
            $filters = $this->filtersFromQuery(false, true);
            $filterError = $e->getMessage();
        }

        $page = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
        $perPage = isset($_GET['por_pagina']) ? (int) $_GET['por_pagina'] : 25;
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $result = $this->repository->search($filters, $page, $perPage);
        $pages = max(1, (int) ceil($result['total'] / $perPage));
        if ($page > $pages && $result['total'] > 0) {
            $page = $pages;
            $result = $this->repository->search($filters, $page, $perPage);
        }

        $this->view->render('agents/index', [
            'title' => 'Agentes de Trânsito',
            'currentUser' => $user,
            'activeNav' => 'agents',
            'items' => $result['items'],
            'total' => $result['total'],
            'filters' => $filters,
            'filterError' => $filterError,
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'statusOptions' => $this->service->functionalStatusOptions(),
            'sourceOptions' => $this->service->sourceOptions(),
            'canCreate' => $user->can(PermissionCodes::AGENTE_CRIAR),
            'canEdit' => $user->can(PermissionCodes::AGENTE_EDITAR),
            'canImport' => $user->can(PermissionCodes::AGENTE_IMPORTAR),
            'canExport' => $user->can(PermissionCodes::AGENTE_EXPORTAR),
            'canDelete' => $user->can(PermissionCodes::AGENTE_EXCLUIR),
            'canViewSensitive' => $canSensitive,
            'canViewBiometry' => $user->can(PermissionCodes::BIOMETRIA_VISUALIZAR),
            'notice' => $this->noticeMessage(isset($_GET['notice']) ? (string) $_GET['notice'] : ''),
        ]);
    }

    public function export(): never
    {
        $user = $this->require(PermissionCodes::AGENTE_EXPORTAR);
        $canSensitive = $user->can(PermissionCodes::AGENTE_VISUALIZAR_DADOS_SENSIVEIS);
        try {
            $filters = $this->filtersFromQuery($canSensitive);
            $rows = $this->exportService->export($filters, $user);
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_die(esc_html($e->getMessage()), 'SMTT CIF - Exportação', ['response' => 422, 'back_link' => true]);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="agentes-cif-' . gmdate('Ymd-His') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit;
        }
        fputcsv($out, ['matricula', 'nome_completo', 'situacao_funcional', 'data_admissao', 'versao_biografica', 'fonte', 'fonte_referencia', 'atualizado_em'], ';');
        foreach ($rows as $row) {
            fputcsv($out, [
                (string) $row['matricula'],
                (string) $row['nome_completo'],
                (string) $row['situacao_funcional_atual'],
                (string) ($row['data_admissao'] ?? ''),
                (string) $row['dados_versao'],
                (string) $row['fonte'],
                (string) ($row['fonte_referencia'] ?? ''),
                (string) $row['atualizado_em'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    public function create(): never
    {
        $user = $this->require(PermissionCodes::AGENTE_CRIAR);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->verifyNonce('smtt_cif_agent_create');
            try {
                $agent = $this->service->create($this->postedData(), $user);
                wp_safe_redirect(add_query_arg('notice', 'created', home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']))));
                exit;
            } catch (\InvalidArgumentException|RuntimeException $e) {
                $this->renderForm($user, 'create', $this->postedData(), $e->getMessage(), null, 422);
            } catch (\Throwable $e) {
                error_log('[SMTT CIF] Falha ao cadastrar agente: ' . $e->getMessage());
                $this->renderForm($user, 'create', $this->postedData(), 'Não foi possível concluir o cadastro. Consulte o log técnico do sistema.', null, 500);
            }
        }

        $this->renderForm($user, 'create', $this->emptyForm(), null, null);
    }

    public function show(string $uuid): never
    {
        $user = $this->require(PermissionCodes::AGENTE_VISUALIZAR);
        try {
            $agent = $this->service->details(
                $uuid,
                $user->can(PermissionCodes::AGENTE_VISUALIZAR_DADOS_SENSIVEIS)
            );
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        }

        $canViewBiometry = $user->can(PermissionCodes::BIOMETRIA_VISUALIZAR);
        $biometry = null;
        if ($canViewBiometry) {
            try {
                $biometry = $this->biometricService->overview($uuid);
            } catch (RuntimeException) {
                $biometry = null;
            }
        }

        $this->view->render('agents/show', [
            'title' => (string) $agent['nome_completo'],
            'currentUser' => $user,
            'activeNav' => 'agents',
            'agent' => $agent,
            'canEdit' => $user->can(PermissionCodes::AGENTE_EDITAR),
            'canViewSensitive' => $user->can(PermissionCodes::AGENTE_VISUALIZAR_DADOS_SENSIVEIS),
            'canViewBiometry' => $canViewBiometry,
            'canCaptureBiometry' => $user->can(PermissionCodes::BIOMETRIA_CAPTURAR),
            'canCreateProcess' => $user->can(PermissionCodes::PROCESSO_CRIAR),
            'canDelete' => $user->can(PermissionCodes::AGENTE_EXCLUIR),
            'biometry' => $biometry,
            'summary' => $this->repository->operationalSummary((int) $agent['id']),
            'dependencies' => $this->repository->dependencyCounts((int) $agent['id']),
            'recentProcesses' => $this->repository->recentProcesses((int) $agent['id']),
            'recentEmissions' => $this->repository->recentEmissions((int) $agent['id']),
            'notice' => $this->noticeMessage(isset($_GET['notice']) ? (string) $_GET['notice'] : ''),
        ]);
    }


    public function status(string $uuid): never
    {
        $user = $this->require(PermissionCodes::AGENTE_EDITAR);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Operação inválida.');
        }
        $this->verifyNonce('smtt_cif_agent_status_' . $uuid);
        $active = isset($_POST['active']) && (string) $_POST['active'] === '1';
        try {
            $this->service->setRegistrationActive($uuid, $active, $user);
            $notice = $active ? 'reactivated' : 'archived';
            wp_safe_redirect(add_query_arg('notice', $notice, home_url('/cif/agentes/' . rawurlencode($uuid))));
            exit;
        } catch (RuntimeException $e) {
            wp_die(esc_html($e->getMessage()), 'SMTT CIF - Gestão', ['response' => 422, 'back_link' => true]);
        }
    }

    public function delete(string $uuid): never
    {
        $user = $this->require(PermissionCodes::AGENTE_EXCLUIR);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Operação inválida.');
        }
        $this->verifyNonce('smtt_cif_agent_delete_' . $uuid);
        $reason = isset($_POST['delete_reason']) ? sanitize_textarea_field((string) wp_unslash($_POST['delete_reason'])) : '';
        try {
            $this->service->discardBeforeFirstEmission($uuid, $reason, $user);
            wp_safe_redirect(add_query_arg('notice', 'deleted', home_url('/cif/agentes')));
            exit;
        } catch (RuntimeException $e) {
            wp_die(esc_html($e->getMessage()), 'SMTT CIF - Gestão', ['response' => 422, 'back_link' => true]);
        }
    }

    public function edit(string $uuid): never
    {
        $user = $this->require(PermissionCodes::AGENTE_EDITAR);
        $this->require(PermissionCodes::AGENTE_VISUALIZAR_DADOS_SENSIVEIS);
        try {
            $current = $this->service->details($uuid, true);
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->verifyNonce('smtt_cif_agent_edit_' . $uuid);
            $lockVersion = isset($_POST['lock_version']) ? (int) $_POST['lock_version'] : -1;
            try {
                $agent = $this->service->update($uuid, $this->postedData(), $lockVersion, $user);
                wp_safe_redirect(add_query_arg('notice', 'updated', home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']))));
                exit;
            } catch (\InvalidArgumentException|RuntimeException $e) {
                $this->renderForm($user, 'edit', $this->postedData(), $e->getMessage(), $current, 422);
            } catch (\Throwable $e) {
                error_log('[SMTT CIF] Falha ao atualizar agente: ' . $e->getMessage());
                $this->renderForm($user, 'edit', $this->postedData(), 'Não foi possível concluir a atualização. Consulte o log técnico do sistema.', $current, 500);
            }
        }

        $this->renderForm($user, 'edit', [
            'matricula' => (string) $current['matricula'],
            'nome_completo' => (string) $current['nome_completo'],
            'naturalidade' => (string) $current['naturalidade'],
            'data_nascimento' => (string) $current['data_nascimento'],
            'cpf' => (string) $current['cpf'],
            'tipo_sanguineo' => (string) $current['tipo_sanguineo'],
            'data_admissao' => (string) $current['data_admissao'],
            'situacao_funcional' => (string) $current['situacao_funcional_atual'],
            'fonte_referencia' => (string) ($current['fonte_referencia'] ?? ''),
        ], null, $current);
    }

    private function renderForm(
        \SMTT\CIF\Gestao\Security\CifUser $user,
        string $mode,
        array $form,
        ?string $error,
        ?array $agent,
        int $status = 200
    ): never {
        $this->view->render('agents/form', [
            'title' => $mode === 'create' ? 'Cadastrar agente' : 'Editar agente',
            'currentUser' => $user,
            'activeNav' => 'agents',
            'mode' => $mode,
            'form' => $form,
            'agent' => $agent,
            'error' => $error,
            'canDelete' => $user->can(PermissionCodes::AGENTE_EXCLUIR),
            'dependencies' => $agent !== null ? $this->repository->dependencyCounts((int) $agent['id']) : [],
            'statusOptions' => $this->service->functionalStatusOptions(),
            'bloodTypes' => $this->service->bloodTypes(),
        ], $status);
    }

    /** @return array<string,string> */
    private function postedData(): array
    {
        $keys = [
            'matricula', 'nome_completo', 'naturalidade', 'data_nascimento', 'cpf',
            'tipo_sanguineo', 'data_admissao', 'situacao_funcional', 'fonte_referencia',
        ];
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = isset($_POST[$key])
                ? sanitize_text_field((string) wp_unslash($_POST[$key]))
                : '';
        }
        return $data;
    }

    /** @return array<string,string> */
    private function emptyForm(): array
    {
        return [
            'matricula' => '',
            'nome_completo' => '',
            'naturalidade' => '',
            'data_nascimento' => '',
            'cpf' => '',
            'tipo_sanguineo' => '',
            'data_admissao' => '',
            'situacao_funcional' => 'ATIVO',
            'fonte_referencia' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function filtersFromQuery(bool $canSensitive, bool $ignoreCpf = false): array
    {
        $get = static fn(string $key): string => isset($_GET[$key])
            ? sanitize_text_field((string) wp_unslash($_GET[$key]))
            : '';

        $filters = [
            'q' => trim($get('q')),
            'situacao' => strtoupper(trim($get('situacao'))),
            'fonte' => strtoupper(trim($get('fonte'))),
            'admissao' => $this->dateFilter($get('admissao')),
            'cadastro' => $this->dateFilter($get('cadastro')),
            'atualizado' => $this->dateFilter($get('atualizado')),
            'ativo' => in_array($get('ativo'), ['0', '1'], true) ? $get('ativo') : '',
            'ordenar' => in_array($get('ordenar'), ['nome', 'matricula', 'situacao', 'ativo', 'admissao', 'cadastro', 'atualizado'], true) ? $get('ordenar') : 'nome',
            'direcao' => strtoupper($get('direcao')) === 'DESC' ? 'DESC' : 'ASC',
        ];

        if ($filters['situacao'] !== '' && !array_key_exists($filters['situacao'], $this->service->functionalStatusOptions())) {
            $filters['situacao'] = '';
        }
        if ($filters['fonte'] !== '' && !array_key_exists($filters['fonte'], $this->service->sourceOptions())) {
            $filters['fonte'] = '';
        }

        $cpf = trim($get('cpf'));
        $filters['cpf'] = $cpf;
        if ($cpf !== '' && !$ignoreCpf) {
            if (!$canSensitive) {
                throw new \InvalidArgumentException('Seu perfil não possui permissão para pesquisar por CPF.');
            }
            $filters['cpf_lookup'] = $this->service->cpfLookupForSearch($cpf);
        }
        return $filters;
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
            'created' => ['type' => 'success', 'text' => 'Agente cadastrado com sucesso. A versão biográfica 1 foi preservada no histórico.'],
            'updated' => ['type' => 'success', 'text' => 'Cadastro atualizado com sucesso. Alterações biográficas geram uma nova versão histórica.'],
            'archived' => ['type' => 'success', 'text' => 'Cadastro arquivado. O histórico foi preservado e o agente saiu da operação ativa.'],
            'reactivated' => ['type' => 'success', 'text' => 'Cadastro reativado.'],
            'deleted' => ['type' => 'success', 'text' => 'Cadastro descartado antes da primeira emissão. Vínculos preparatórios foram removidos e a operação ficou registrada na auditoria.'],
            default => null,
        };
    }

    private function forbidden(string $message): never
    {
        $this->view->render('error/forbidden', [
            'title' => 'Acesso negado',
            'message' => $message,
            'currentUser' => $this->gate->user(),
        ], 403);
    }

    private function notFound(string $message): never
    {
        $this->view->render('error/not-found', [
            'title' => 'Agente não encontrado',
            'message' => $message,
            'currentUser' => $this->gate->user(),
        ], 404);
    }
}
