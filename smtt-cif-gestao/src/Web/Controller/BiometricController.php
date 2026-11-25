<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use RuntimeException;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Service\BiometricService;
use SMTT\CIF\Gestao\Web\View;

final class BiometricController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly BiometricService $service
    ) {
    }

    public function index(): never
    {
        $user = $this->require(PermissionCodes::BIOMETRIA_VISUALIZAR);
        $page = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
        $perPage = isset($_GET['por_pagina']) ? (int) $_GET['por_pagina'] : 25;
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $filters = [
            'q' => isset($_GET['q']) ? sanitize_text_field((string) wp_unslash($_GET['q'])) : '',
            'biometria' => isset($_GET['biometria']) ? sanitize_key((string) wp_unslash($_GET['biometria'])) : '',
            'situacao' => isset($_GET['situacao']) ? strtoupper(sanitize_key((string) wp_unslash($_GET['situacao']))) : '',
        ];

        $result = $this->service->catalog($filters, $page, $perPage);
        $pages = max(1, (int) ceil($result['total'] / $perPage));
        if ($page > $pages && $result['total'] > 0) {
            $page = $pages;
            $result = $this->service->catalog($filters, $page, $perPage);
        }

        $this->view->render('biometry/index', [
            'title' => 'Biometria',
            'currentUser' => $user,
            'activeNav' => 'biometry',
            'items' => $result['items'],
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'canCapture' => $user->can(PermissionCodes::BIOMETRIA_CAPTURAR),
        ]);
    }

    public function manage(string $agentUuid): never
    {
        $user = $this->require(PermissionCodes::BIOMETRIA_VISUALIZAR);
        $canCapture = $user->can(PermissionCodes::BIOMETRIA_CAPTURAR);
        $canInvalidate = $user->can(PermissionCodes::BIOMETRIA_INVALIDAR);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$canCapture) {
                $this->forbidden('Seu perfil não possui permissão para registrar coleta biométrica.');
            }
            $this->verifyNonce('smtt_cif_biometry_capture_' . $agentUuid);
            try {
                $this->service->capture(
                    $agentUuid,
                    $this->postedData(),
                    isset($_FILES['foto']) && is_array($_FILES['foto']) ? $_FILES['foto'] : [],
                    isset($_FILES['assinatura']) && is_array($_FILES['assinatura']) ? $_FILES['assinatura'] : [],
                    $user
                );
                wp_safe_redirect(add_query_arg('notice', 'biometry-created', home_url('/cif/agentes/' . rawurlencode($agentUuid) . '/biometria')));
                exit;
            } catch (\InvalidArgumentException|RuntimeException $e) {
                $this->renderManage($user, $agentUuid, $e->getMessage(), $this->postedData(), 422);
            } catch (\Throwable $e) {
                error_log('[SMTT CIF] Falha na coleta biométrica: ' . $e->getMessage());
                $this->renderManage($user, $agentUuid, 'Não foi possível concluir a coleta biométrica. Consulte o log técnico.', $this->postedData(), 500);
            }
        }

        $this->renderManage($user, $agentUuid, null, $this->emptyForm(), 200, $canCapture, $canInvalidate);
    }

    public function invalidate(string $agentUuid, string $collectionUuid): never
    {
        $user = $this->require(PermissionCodes::BIOMETRIA_INVALIDAR);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            wp_safe_redirect(home_url('/cif/agentes/' . rawurlencode($agentUuid) . '/biometria'));
            exit;
        }
        $this->verifyNonce('smtt_cif_biometry_invalidate_' . $collectionUuid);
        $reason = isset($_POST['motivo']) ? sanitize_textarea_field((string) wp_unslash($_POST['motivo'])) : '';
        try {
            $this->service->invalidate($agentUuid, $collectionUuid, $reason, $user);
            wp_safe_redirect(add_query_arg('notice', 'biometry-invalidated', home_url('/cif/agentes/' . rawurlencode($agentUuid) . '/biometria')));
            exit;
        } catch (\InvalidArgumentException|RuntimeException $e) {
            $this->renderManage($user, $agentUuid, $e->getMessage(), $this->emptyForm(), 422, $user->can(PermissionCodes::BIOMETRIA_CAPTURAR), true);
        }
    }

    public function file(string $fileUuid): never
    {
        $this->require(PermissionCodes::BIOMETRIA_VISUALIZAR);
        try {
            $file = $this->service->privateFile($fileUuid);
        } catch (RuntimeException $e) {
            status_header(404);
            exit;
        }
        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: ' . $file['mime_type']);
            header('Content-Length: ' . (string) $file['size']);
            header('Content-Disposition: inline; filename="biometria"');
            header('X-Content-Type-Options: nosniff');
        }
        echo $file['bytes'];
        exit;
    }

    private function renderManage(
        \SMTT\CIF\Gestao\Security\CifUser $user,
        string $agentUuid,
        ?string $error,
        array $form,
        int $status,
        ?bool $canCapture = null,
        ?bool $canInvalidate = null
    ): never {
        try {
            $overview = $this->service->overview($agentUuid);
        } catch (RuntimeException $e) {
            $this->view->render('error/not-found', [
                'title' => 'Agente não encontrado',
                'message' => $e->getMessage(),
                'currentUser' => $user,
            ], 404);
        }
        $this->view->render('biometry/manage', [
            'title' => 'Biometria — ' . (string) $overview['agent']['nome_completo'],
            'currentUser' => $user,
            'activeNav' => 'biometry',
            'overview' => $overview,
            'error' => $error,
            'form' => $form,
            'canCapture' => $canCapture ?? $user->can(PermissionCodes::BIOMETRIA_CAPTURAR),
            'canInvalidate' => $canInvalidate ?? $user->can(PermissionCodes::BIOMETRIA_INVALIDAR),
            'notice' => $this->notice(isset($_GET['notice']) ? (string) $_GET['notice'] : ''),
        ], $status);
    }

    /** @return array<string,string> */
    private function postedData(): array
    {
        $data = [];
        foreach (['local_coleta', 'observacoes'] as $key) {
            $data[$key] = isset($_POST[$key]) ? sanitize_text_field((string) wp_unslash($_POST[$key])) : '';
        }
        foreach (['foto_fundo_branco', 'foto_padrao_icao', 'foto_apresentacao_pessoal', 'foto_camisa_caqui', 'assinatura_coleta_eletronica'] as $key) {
            $data[$key] = isset($_POST[$key]) ? '1' : '';
        }
        return $data;
    }

    /** @return array<string,string> */
    private function emptyForm(): array
    {
        return [
            'local_coleta' => 'Unidade de Gestão de Pessoas',
            'observacoes' => '',
            'foto_fundo_branco' => '',
            'foto_padrao_icao' => '',
            'foto_apresentacao_pessoal' => '',
            'foto_camisa_caqui' => '',
            'assinatura_coleta_eletronica' => '',
        ];
    }

    /** @return array{type:string,text:string}|null */
    private function notice(string $code): ?array
    {
        return match ($code) {
            'biometry-created' => ['type' => 'success', 'text' => 'Coleta biométrica registrada. Fotografia e assinatura foram preservadas em armazenamento privado criptografado.'],
            'biometry-invalidated' => ['type' => 'success', 'text' => 'Coleta biométrica invalidada. O histórico e os arquivos foram preservados.'],
            default => null,
        };
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

    private function forbidden(string $message): never
    {
        $this->view->render('error/forbidden', [
            'title' => 'Acesso negado',
            'message' => $message,
            'currentUser' => $this->gate->user(),
        ], 403);
    }
}
