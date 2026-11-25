<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use RuntimeException;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Import\AgentTemplateBuilder;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Service\AgentImportService;
use SMTT\CIF\Gestao\Web\View;

final class AgentImportController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly AgentImportService $service,
        private readonly AgentTemplateBuilder $templates
    ) {
    }

    public function index(): never
    {
        $user = $this->require(PermissionCodes::AGENTE_IMPORTAR);
        $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->verifyNonce('smtt_cif_agent_import_prepare');
            try {
                $file = $_FILES['arquivo'] ?? null;
                if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name'])) {
                    throw new RuntimeException('Selecione um arquivo CSV ou XLSX.');
                }
                if ((int) $file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException($this->uploadError((int) $file['error']));
                }
                $tmp = (string) $file['tmp_name'];
                if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
                    throw new RuntimeException('O arquivo recebido não passou pela validação de upload do PHP.');
                }
                $batch = $this->service->prepare($tmp, (string) $file['name'], $user);
                wp_safe_redirect(home_url('/cif/agentes/importacoes/' . rawurlencode((string) $batch['uuid'])));
                exit;
            } catch (RuntimeException|\InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                error_log('[SMTT CIF] Falha ao preparar importação: ' . $e->getMessage());
                $error = 'Não foi possível preparar a importação. Consulte o log técnico do sistema.';
            }
        }

        $this->view->render('agents/import', [
            'title' => 'Importar Agentes de Trânsito',
            'currentUser' => $user,
            'activeNav' => 'agents',
            'error' => $error,
            'recentBatches' => $this->service->recent(),
            'xlsxAvailable' => class_exists('ZipArchive') && function_exists('simplexml_load_string'),
        ], $error === null ? 200 : 422);
    }

    public function batch(string $uuid): never
    {
        $user = $this->require(PermissionCodes::AGENTE_IMPORTAR);
        $error = null;
        $notice = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->verifyNonce('smtt_cif_agent_import_confirm_' . $uuid);
            try {
                $processed = $this->service->confirm($uuid, $user);
                $notice = (string) ($processed['status'] ?? '') === 'PROCESSING'
                    ? ['type' => 'warning', 'text' => 'O lote ainda possui linhas em processamento. Aguarde alguns instantes e utilize Retomar processamento se necessário.']
                    : ['type' => 'success', 'text' => 'Processamento do lote concluído. Confira o resumo e as linhas abaixo.'];
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                error_log('[SMTT CIF] Falha ao confirmar importação: ' . $e->getMessage());
                $error = 'Não foi possível concluir o lote. Ele pode ser retomado com segurança; consulte o log técnico.';
            }
        }

        try {
            $batch = $this->service->details($uuid);
        } catch (RuntimeException $e) {
            $this->notFound($e->getMessage());
        }

        $this->view->render('agents/import-batch', [
            'title' => 'Lote de importação',
            'currentUser' => $user,
            'activeNav' => 'agents',
            'batch' => $batch,
            'error' => $error,
            'notice' => $notice,
        ], $error === null ? 200 : 422);
    }

    public function csvTemplate(): never
    {
        $this->require(PermissionCodes::AGENTE_IMPORTAR);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="modelo-importacao-agentes.csv"');
        echo $this->templates->csv();
        exit;
    }

    public function xlsxTemplate(): never
    {
        $this->require(PermissionCodes::AGENTE_IMPORTAR);
        try {
            $file = $this->templates->xlsxFile();
        } catch (RuntimeException $e) {
            wp_die(esc_html($e->getMessage()), 'SMTT CIF - Modelo XLSX', ['response' => 422, 'back_link' => true]);
        }

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="modelo-importacao-agentes.xlsx"');
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        @unlink($file);
        exit;
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

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o limite de upload configurado no servidor.',
            UPLOAD_ERR_PARTIAL => 'O upload foi recebido apenas parcialmente.',
            UPLOAD_ERR_NO_FILE => 'Selecione um arquivo para importar.',
            UPLOAD_ERR_NO_TMP_DIR => 'O servidor não possui diretório temporário disponível.',
            UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar o arquivo temporário.',
            UPLOAD_ERR_EXTENSION => 'Uma extensão PHP interrompeu o upload.',
            default => 'Falha no upload do arquivo.',
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
            'title' => 'Importação não encontrada',
            'message' => $message,
            'currentUser' => $this->gate->user(),
        ], 404);
    }
}
