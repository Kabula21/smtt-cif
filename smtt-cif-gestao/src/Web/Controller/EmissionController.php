<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use RuntimeException;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Domain\Emission\EmissionStatus;
use SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessType;
use SMTT\CIF\Gestao\Repository\EmissionRepository;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Service\EmissionService;
use SMTT\CIF\Gestao\Service\CardArtifactService;
use SMTT\CIF\Gestao\Service\EmissionSetupService;
use SMTT\CIF\Gestao\Service\DeliveryService;
use SMTT\CIF\Gestao\Service\SignedDeliveryTermService;
use SMTT\CIF\Gestao\Web\View;

final class EmissionController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly EmissionRepository $repository,
        private readonly EmissionService $service,
        private readonly EmissionSetupService $setup,
        private readonly CardArtifactService $artifacts,
        private readonly DeliveryService $delivery,
        private readonly SignedDeliveryTermService $signedTerms
    ) {
    }

    public function index(): never
    {
        $user = $this->require(PermissionCodes::EMISSAO_VISUALIZAR);
        $get = static fn(string $key): string => isset($_GET[$key]) ? sanitize_text_field((string) wp_unslash($_GET[$key])) : '';
        $type = strtoupper(trim($get('tipo')));
        $status = strtoupper(trim($get('status')));
        $filters = [
            'q' => trim($get('q')),
            'status' => in_array($status, [EmissionStatus::PENDENTE_ATIVACAO, EmissionStatus::ATIVA, EmissionStatus::SUSPENSA, EmissionStatus::ENCERRADA], true) ? $status : '',
            'tipo' => array_key_exists($type, ProcessType::options()) ? $type : '',
            'emitida' => $this->dateFilter($get('emitida')),
            'ordenar' => in_array($get('ordenar'), ['codigo', 'nome', 'matricula', 'tipo', 'emitida', 'validade', 'fisico'], true) ? $get('ordenar') : 'emitida',
            'direcao' => strtoupper($get('direcao')) === 'ASC' ? 'ASC' : 'DESC',
        ];
        $page = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
        $perPage = isset($_GET['por_pagina']) ? (int) $_GET['por_pagina'] : 25;
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $result = $this->repository->search($filters, $page, $perPage);
        $pages = max(1, (int) ceil($result['total'] / $perPage));
        if ($page > $pages && $result['total'] > 0) {
            $page = $pages;
            $result = $this->repository->search($filters, $page, $perPage);
        }
        foreach ($result['items'] as &$item) {
            $item['status_label'] = EmissionStatus::label((string) $item['status_validade']);
        }
        unset($item);

        $this->view->render('emissions/index', [
            'title' => 'Emissões CIF',
            'currentUser' => $user,
            'activeNav' => 'emissions',
            'items' => $result['items'],
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'canSetup' => $user->isSystemAdministrator(),
            'typeOptions' => ProcessType::options(),
        ]);
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

    public function show(string $uuid): never
    {
        $user = $this->require(PermissionCodes::EMISSAO_VISUALIZAR);
        try {
            $emission = $this->service->details($uuid);
            $token = $user->can(PermissionCodes::EMISSAO_VISUALIZAR_TOKEN_QR) ? $this->service->qrToken($uuid) : null;
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        }
        try {
            $artifactSummary = $this->artifacts->summary($uuid);
        } catch (\Throwable $e) {
            error_log('[SMTT CIF] Falha ao carregar resumo de artefatos: ' . $e->getMessage());
            $artifactSummary = ['qr_base_url' => null, 'qr_url' => null, 'artifact' => null, 'prints' => []];
        }
        $signedTerm = $this->repository->latestSignedDeliveryTerm((int) $emission['id']);
        $this->view->render('emissions/show', [
            'title' => 'Emissão CIF',
            'currentUser' => $user,
            'activeNav' => 'emissions',
            'emission' => $emission,
            'signedTerm' => $signedTerm,
            'qrToken' => $token,
            'artifactSummary' => $artifactSummary,
            'canPrint' => $user->can(PermissionCodes::EMISSAO_IMPRIMIR),
            'canViewQr' => $user->can(PermissionCodes::EMISSAO_IMPRIMIR) || $user->can(PermissionCodes::EMISSAO_VISUALIZAR_TOKEN_QR),
            'canDeliver' => $user->can(PermissionCodes::EMISSAO_ATIVAR) && $user->can(PermissionCodes::EMISSAO_ENTREGAR),
            'canRegisterOccurrence' => $user->can(PermissionCodes::OCORRENCIA_REGISTRAR),
            'canRegisterRecollection' => $user->can(PermissionCodes::RECOLHIMENTO_REGISTRAR),
            'canCancel' => $user->can(PermissionCodes::EMISSAO_CANCELAR),
            'cancellationReasons' => EmissionService::cancellationReasons(),
            'deliveryMethods' => DeliveryService::methods(),
            'notice' => $this->emissionNotice(isset($_GET['notice']) ? (string) $_GET['notice'] : ''),
            'error' => isset($_GET['erro']) ? sanitize_text_field((string) wp_unslash($_GET['erro'])) : null,
        ]);
    }

    public function generate(string $processUuid): never
    {
        $user = $this->require(PermissionCodes::EMISSAO_GERAR);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $this->verifyNonce('smtt_cif_emission_generate_' . $processUuid);
        try {
            $emission = $this->service->generateFromProcess($processUuid, $user);
            wp_safe_redirect(add_query_arg('notice', 'generated', home_url('/cif/emissoes/' . rawurlencode((string) $emission['uuid']))));
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/processos/' . rawurlencode($processUuid))));
            exit;
        } catch (\Throwable $e) {
            error_log('[SMTT CIF] Falha ao gerar emissão: ' . $e->getMessage());
            wp_safe_redirect(add_query_arg('erro', 'Não foi possível gerar a emissão. Consulte o log técnico.', home_url('/cif/processos/' . rawurlencode($processUuid))));
            exit;
        }
    }

    public function printReceiptTerm(string $uuid): never
    {
        // Inclui CPF completo; não é uma rota pública nem para leitores sem permissão de entrega.
        $this->require(PermissionCodes::EMISSAO_ENTREGAR);
        try {
            $term = $this->service->receiptTermData($uuid);
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[SMTT CIF] Falha ao preparar termo de entrega: ' . $e->getMessage());
            $this->notFound('Não foi possível preparar o termo de entrega.');
        }
        $logoBytes = file_get_contents(SMTT_CIF_GESTAO_PATH . 'assets/img/smtt-logo-termo.png');
        if (!is_string($logoBytes) || $logoBytes === '') {
            $this->notFound('Marca institucional do termo não localizada.');
        }
        $logoDataUri = 'data:image/png;base64,' . base64_encode($logoBytes);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        require SMTT_CIF_GESTAO_PATH . 'templates/emissions/receipt-term.php';
        exit;
    }

    public function attachSignedTerm(string $uuid): never
    {
        $user = $this->require(PermissionCodes::EMISSAO_ENTREGAR);
        if (!$user->can(PermissionCodes::EMISSAO_ATIVAR)) {
            $this->forbidden('É necessária permissão de entrega e ativação para conferir o termo assinado.');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $this->verifyNonce('smtt_cif_signed_term_' . $uuid);
        try {
            $this->signedTerms->attach(
                $uuid,
                is_array($_FILES['termo_assinado'] ?? null) ? $_FILES['termo_assinado'] : [],
                isset($_POST['assinaturas_conferidas']) && (string) $_POST['assinaturas_conferidas'] === '1',
                $user
            );
            wp_safe_redirect(add_query_arg('notice', 'signed-term-attached', home_url('/cif/emissoes/' . rawurlencode($uuid))));
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/emissoes/' . rawurlencode($uuid))));
            exit;
        } catch (\Throwable $e) {
            error_log('[SMTT CIF] Falha ao anexar termo assinado: ' . $e->getMessage());
            wp_safe_redirect(add_query_arg('erro', 'Não foi possível anexar o termo assinado. Consulte o log técnico.', home_url('/cif/emissoes/' . rawurlencode($uuid))));
            exit;
        }
    }

    public function signedTermFile(string $uuid, string $fileUuid): never
    {
        $user = $this->require(PermissionCodes::EMISSAO_ENTREGAR);
        if (!$user->can(PermissionCodes::EMISSAO_ATIVAR)) {
            $this->forbidden('É necessária permissão de entrega e ativação para consultar o termo assinado.');
        }
        try {
            $file = $this->signedTerms->read($uuid, $fileUuid);
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        }
        nocache_headers();
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . (string) $file['size']);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Content-Security-Policy: sandbox');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($file['name']) . '"');
        echo $file['bytes'];
        exit;
    }

    public function deliver(string $uuid): never
    {
        $user = $this->require(PermissionCodes::EMISSAO_ENTREGAR);
        if (!$user->can(PermissionCodes::EMISSAO_ATIVAR)) {
            $this->forbidden('A operação de entrega também exige permissão para ativar a emissão CIF.');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $this->verifyNonce('smtt_cif_emission_deliver_' . $uuid);
        try {
            $this->delivery->deliverAndActivate($uuid, [
                'titular_confirmou' => isset($_POST['titular_confirmou']) ? 1 : 0,
                'metodo_confirmacao' => 'TERMO_ASSINADO',
                'referencia' => isset($_POST['referencia']) ? sanitize_text_field((string) wp_unslash($_POST['referencia'])) : '',
                'anterior_recolhida' => isset($_POST['anterior_recolhida']) ? 1 : 0,
                'observacoes' => isset($_POST['observacoes']) ? sanitize_textarea_field((string) wp_unslash($_POST['observacoes'])) : '',
            ], $user);
            wp_safe_redirect(add_query_arg('notice', 'delivered', home_url('/cif/emissoes/' . rawurlencode($uuid))));
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/emissoes/' . rawurlencode($uuid))));
            exit;
        } catch (\Throwable $e) {
            error_log('[SMTT CIF] Falha ao registrar entrega/ativação: ' . $e->getMessage());
            wp_safe_redirect(add_query_arg('erro', 'Não foi possível registrar a entrega/ativação. Consulte o log técnico.', home_url('/cif/emissoes/' . rawurlencode($uuid))));
            exit;
        }
    }

    public function cancel(string $uuid): never
    {
        $user = $this->require(PermissionCodes::EMISSAO_CANCELAR);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $this->verifyNonce('smtt_cif_emission_cancel_' . $uuid);
        try {
            $this->service->cancelPending($uuid, [
                'motivo_codigo' => isset($_POST['motivo_codigo']) ? sanitize_text_field((string)wp_unslash($_POST['motivo_codigo'])) : '',
                'justificativa' => isset($_POST['justificativa']) ? sanitize_textarea_field((string)wp_unslash($_POST['justificativa'])) : '',
            ], $user);
            wp_safe_redirect(add_query_arg('notice', 'cancelled', home_url('/cif/emissoes/' . rawurlencode($uuid))));
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/emissoes/' . rawurlencode($uuid))));
            exit;
        } catch (\Throwable $e) {
            error_log('[SMTT CIF] Falha ao cancelar emissão: ' . $e->getMessage());
            wp_safe_redirect(add_query_arg('erro', 'Não foi possível cancelar a emissão. Consulte o log técnico.', home_url('/cif/emissoes/' . rawurlencode($uuid))));
            exit;
        }
    }

    public function setup(): never
    {
        $user = $this->requireAdmin();
        $setupError = null;
        try {
            $overview = $this->setup->overview($user);
        } catch (RuntimeException $e) {
            $setupError = $e->getMessage();
            $overview = $this->setup->overview();
        }
        $this->view->render('emissions/setup', [
            'title' => 'Preparação para emissão',
            'currentUser' => $user,
            'activeNav' => 'settings',
            'overview' => $overview,
            'canManageCycle' => $user->can(PermissionCodes::NUMERACAO_GERENCIAR_CICLO),
            'canManageSigner' => $user->can(PermissionCodes::SIGNATARIO_GERENCIAR),
            'canEditConfig' => $user->can(PermissionCodes::CONFIGURACAO_EDITAR),
            'validationBaseUrl' => $this->artifacts->validationBaseUrl(),
            'notice' => $this->noticeMessage(isset($_GET['notice']) ? (string) $_GET['notice'] : ''),
            'error' => isset($_GET['erro']) ? sanitize_text_field((string) wp_unslash($_GET['erro'])) : null,
        ]);
    }

    public function openCycle(): never
    {
        $user = $this->requireAdmin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $this->verifyNonce('smtt_cif_open_cycle');
        try {
            $this->setup->openNextCycle([
                'descricao' => isset($_POST['descricao']) ? sanitize_text_field((string) wp_unslash($_POST['descricao'])) : '',
                'ato_referencia' => isset($_POST['ato_referencia']) ? sanitize_text_field((string) wp_unslash($_POST['ato_referencia'])) : '',
                'justificativa' => isset($_POST['justificativa']) ? sanitize_textarea_field((string) wp_unslash($_POST['justificativa'])) : '',
            ], $user);
            wp_safe_redirect(add_query_arg('notice', 'cycle-opened', home_url('/cif/emissoes/preparacao')));
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/emissoes/preparacao')));
            exit;
        }
    }

    public function createSigner(): never
    {
        $user = $this->requireAdmin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $this->verifyNonce('smtt_cif_create_signer');
        try {
            $this->setup->createSigner([
                'nome' => isset($_POST['nome']) ? sanitize_text_field((string) wp_unslash($_POST['nome'])) : '',
                'cargo' => isset($_POST['cargo']) ? sanitize_text_field((string) wp_unslash($_POST['cargo'])) : '',
                'ato_referencia' => isset($_POST['ato_referencia']) ? sanitize_text_field((string) wp_unslash($_POST['ato_referencia'])) : '',
            ], isset($_FILES['assinatura']) && is_array($_FILES['assinatura']) ? $_FILES['assinatura'] : [], $user);
            wp_safe_redirect(add_query_arg('notice', 'signer-created', home_url('/cif/emissoes/preparacao')));
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/emissoes/preparacao')));
            exit;
        }
    }

    public function saveValidationUrl(): never
    {
        $user = $this->requireAdmin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->notFound('Ação não encontrada.');
        }
        $this->verifyNonce('smtt_cif_validation_url');
        $url = isset($_POST['validation_base_url']) ? (string) wp_unslash($_POST['validation_base_url']) : '';
        try {
            $this->artifacts->setValidationBaseUrl($url, $user);
            wp_safe_redirect(add_query_arg('notice', 'validation-url-saved', home_url('/cif/emissoes/preparacao')));
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            wp_safe_redirect(add_query_arg('erro', $e->getMessage(), home_url('/cif/emissoes/preparacao')));
            exit;
        }
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


    private function requireAdmin(): \SMTT\CIF\Gestao\Security\CifUser
    {
        $user = $this->gate->user();
        if ($user === null) {
            wp_safe_redirect(home_url('/cif/login'));
            exit;
        }
        if (!$user->isSystemAdministrator()) {
            $this->forbidden('Esta área é restrita ao Administrador CIF.');
        }
        return $user;
    }

    /** @param list<string> $permissions */
    private function requireAny(array $permissions): \SMTT\CIF\Gestao\Security\CifUser
    {
        $user = $this->gate->user();
        if ($user === null) {
            wp_safe_redirect(home_url('/cif/login'));
            exit;
        }
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return $user;
            }
        }
        $this->forbidden('Seu usuário não possui permissão para acessar esta área.');
    }

    /** @return array{type:string,text:string}|null */
    private function noticeMessage(string $code): ?array
    {
        return match ($code) {
            'cycle-opened' => ['type' => 'success', 'text' => 'Novo ciclo de emissão aberto com sucesso. O sequencial global foi preservado.'],
            'signer-created' => ['type' => 'success', 'text' => 'Nova versão do signatário institucional cadastrada.'],
            'validation-url-saved' => ['type' => 'success', 'text' => 'URL oficial de validação salva. Emissões que já tiveram o destino QR fixado continuam usando o endereço histórico.'],
            default => null,
        };
    }

    /** @return array{type:string,text:string}|null */
    private function emissionNotice(string $code): ?array
    {
        return match ($code) {
            'artifact-generated' => ['type' => 'success', 'text' => 'Arte técnica de impressão gerada e armazenada de forma privada. O destino do QR desta emissão ficou fixado.'],
            'print-ok' => ['type' => 'success', 'text' => 'Produção da carteira registrada como concluída. A CIF está em guarda na SMTT.'],
            'print-failed' => ['type' => 'warning', 'text' => 'Falha de produção registrada no histórico operacional.'],
            'delivered' => ['type' => 'success', 'text' => 'Termo conferido, entrega registrada e CIF ativada. A emissão anterior foi tratada na mesma transação.'],
            'signed-term-attached' => ['type' => 'success', 'text' => 'Termo assinado anexado e conferido com sucesso. A entrega e ativação agora podem ser registradas.'],
            'cancelled' => ['type' => 'success', 'text' => 'Emissão pendente cancelada administrativamente. O histórico, o código e o QR permanecem preservados e a emissão foi marcada como encerrada.'],
            'occurrence-created' => ['type' => 'success', 'text' => 'Ocorrência registrada e incorporada ao histórico desta CIF.'],
            'occurrence-recovered' => ['type' => 'success', 'text' => 'Recuperação registrada e estado da CIF atualizado.'],
            'recollection-created' => ['type' => 'success', 'text' => 'Recolhimento administrativo registrado no histórico desta CIF.'],
            'recollection-collected' => ['type' => 'success', 'text' => 'Recolhimento físico confirmado.'],
            'recollection-not-collected' => ['type' => 'warning', 'text' => 'Não recolhimento justificado e registrado no histórico.'],
            'recollection-invalidated' => ['type' => 'success', 'text' => 'Inutilização registrada no histórico da CIF.'],
            default => null,
        };
    }

    private function forbidden(string $message): never
    {
        $this->view->render('error/forbidden', ['title' => 'Acesso negado', 'message' => $message, 'currentUser' => $this->gate->user()], 403);
    }

    private function notFound(string $message): never
    {
        $this->view->render('error/not-found', ['title' => 'Emissão não encontrada', 'message' => $message, 'currentUser' => $this->gate->user()], 404);
    }
}
