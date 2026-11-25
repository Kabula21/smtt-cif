<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use RuntimeException;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Service\CardArtifactService;
use SMTT\CIF\Gestao\Web\View;

final class EmissionArtifactController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly CardArtifactService $service
    ) {}

    public function generate(string $uuid): never
    {
        $user=$this->require(PermissionCodes::EMISSAO_IMPRIMIR);
        $this->requirePost();
        $this->verifyNonce('smtt_cif_artifact_generate_'.$uuid);
        try{
            $this->service->generate($uuid,$user);
            if (isset($_POST['abrir_preview']) && (string) $_POST['abrir_preview'] === '1') {
                wp_safe_redirect(home_url('/cif/emissoes/'.rawurlencode($uuid).'/visualizar')); exit;
            }
            wp_safe_redirect(add_query_arg('notice','artifact-generated',home_url('/cif/emissoes/'.rawurlencode($uuid)))); exit;
        }catch(\InvalidArgumentException|RuntimeException $e){
            wp_safe_redirect(add_query_arg('erro',$e->getMessage(),home_url('/cif/emissoes/'.rawurlencode($uuid)))); exit;
        }catch(\Throwable $e){
            error_log('[SMTT CIF] Falha ao gerar artefato CIF: '.$e->getMessage());
            wp_safe_redirect(add_query_arg('erro','Não foi possível gerar a arte de impressão. Consulte o log técnico.',home_url('/cif/emissoes/'.rawurlencode($uuid)))); exit;
        }
    }

    public function preview(string $uuid): never
    {
        $user=$this->require(PermissionCodes::EMISSAO_VISUALIZAR);
        try{$result=$this->service->preview($uuid,$user);}catch(RuntimeException $e){$this->notFound($e->getMessage());}
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
        echo (string)$result['html'];
        exit;
    }

    public function startPrint(string $uuid): never
    {
        $user=$this->require(PermissionCodes::EMISSAO_IMPRIMIR);
        $this->requirePost();
        $this->verifyNonce('smtt_cif_print_start_'.$uuid);
        $printer=isset($_POST['impressora'])?sanitize_text_field((string)wp_unslash($_POST['impressora'])):'';
        try{
            $result=$this->service->openPrint($uuid,$printer,$user);
            $id=(int)($result['attempt']['id']??0);
            if($id<1){throw new RuntimeException('A tentativa de produção não pôde ser identificada.');}
            wp_safe_redirect(home_url('/cif/emissoes/'.rawurlencode($uuid).'/impressao/'.$id)); exit;
        }catch(\InvalidArgumentException|RuntimeException $e){
            wp_safe_redirect(add_query_arg('erro',$e->getMessage(),home_url('/cif/emissoes/'.rawurlencode($uuid)))); exit;
        }
    }

    public function printView(string $uuid, int $attemptId): never
    {
        $this->require(PermissionCodes::EMISSAO_IMPRIMIR);
        try{$result=$this->service->printView($uuid,$attemptId);}catch(RuntimeException $e){$this->notFound($e->getMessage());}
        $html=$this->productionViewHtml((string)$result['html'],$uuid,$attemptId);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function finishPrint(string $uuid, int $attemptId): never
    {
        $user=$this->require(PermissionCodes::EMISSAO_IMPRIMIR);
        $this->requirePost();
        $this->verifyNonce('smtt_cif_print_finish_'.$uuid.'_'.$attemptId);
        $result=isset($_POST['resultado'])?sanitize_text_field((string)wp_unslash($_POST['resultado'])):'';
        $reason=isset($_POST['justificativa'])?sanitize_textarea_field((string)wp_unslash($_POST['justificativa'])):'';
        $returnMode=isset($_POST['return_mode'])?sanitize_text_field((string)wp_unslash($_POST['return_mode'])):'';
        try{
            $this->service->finishPrint($uuid,$attemptId,$result,$reason,$user);
            if($returnMode==='production_tab'){
                $this->renderProductionResult($uuid,$result,null);
            }
            wp_safe_redirect(add_query_arg('notice',$result==='CONCLUIDA'?'print-ok':'print-failed',home_url('/cif/emissoes/'.rawurlencode($uuid)))); exit;
        }catch(\InvalidArgumentException|RuntimeException $e){
            if($returnMode==='production_tab'){
                $this->renderProductionResult($uuid,$result,$e->getMessage());
            }
            wp_safe_redirect(add_query_arg('erro',$e->getMessage(),home_url('/cif/emissoes/'.rawurlencode($uuid)))); exit;
        }
    }

    private function productionViewHtml(string $html, string $uuid, int $attemptId): string
    {
        // A própria arte já contém apenas as ações essenciais: Imprimir / Salvar como PDF e Voltar à emissão.
        // A confirmação da produção acontece na tela da emissão, mantendo a impressão limpa e o fluxo linear.
        return $html;
    }

    private function renderProductionResult(string $uuid, string $result, ?string $error): never
    {
        $ok=$error===null;
        if(!$ok){status_header(422);}
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");

        $emissionUrl=htmlspecialchars((string)home_url('/cif/emissoes/'.rawurlencode($uuid)),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $message=$ok
            ? ($result==='CONCLUIDA' ? 'Produção concluída e registrada. A CIF está agora em guarda na SMTT.' : 'Falha de produção registrada. Nenhuma carteira foi marcada como produzida.')
            : (string)$error;
        $title=$ok ? ($result==='CONCLUIDA'?'Produção registrada':'Falha registrada') : 'Não foi possível registrar';
        $messageEsc=htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $titleEsc=htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $payload=wp_json_encode(['type'=>'smtt-cif-production-updated','emissionUuid'=>$uuid,'result'=>$result,'ok'=>$ok]);
        if(!is_string($payload)){$payload='{}';}

        echo <<<HTML
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>{$titleEsc}</title><style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#eef4f7;font-family:Arial,Helvetica,sans-serif;color:#12202f}.card{width:min(560px,100%);background:#fff;border:1px solid #cbdce5;border-radius:14px;padding:24px;box-shadow:0 12px 32px #173b4b18}.card h1{margin:0 0 10px;font-size:22px;color:#075d7a}.card p{margin:0 0 18px;line-height:1.55;color:#4f6673}.actions{display:flex;flex-wrap:wrap;gap:8px}.actions button,.actions a{display:inline-flex;align-items:center;padding:10px 14px;border-radius:8px;font-weight:700;text-decoration:none;cursor:pointer}.actions button{border:0;background:#087fa1;color:#fff}.actions a{border:1px solid #bfd6df;background:#eef6f9;color:#075d7a}
</style></head><body><main class="card"><h1>{$titleEsc}</h1><p>{$messageEsc}</p><div class="actions"><button type="button" onclick="window.close()">Fechar esta aba</button><a href="{$emissionUrl}">Abrir emissão aqui</a></div></main><script>
(function(){var payload=$payload;try{if(window.opener&&!window.opener.closed){window.opener.postMessage(payload,window.location.origin);}}catch(e){}try{payload.at=Date.now();window.localStorage.setItem('smtt-cif-production-updated',JSON.stringify(payload));}catch(e){}})();
</script></body></html>
HTML;
        exit;
    }

    private function requirePost(): void
    {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){$this->notFound('Ação não encontrada.');}
    }
    private function verifyNonce(string $action):void
    {
        $nonce=isset($_POST['smtt_cif_nonce'])?(string)wp_unslash($_POST['smtt_cif_nonce']):'';
        if(!\smtt_cif_verify_nonce($nonce,$action)){$this->forbidden('A sessão do formulário expirou. Atualize a página e tente novamente.');}
    }
    private function require(string $permission): \SMTT\CIF\Gestao\Security\CifUser
    {
        try{return $this->gate->requirePermission($permission);}catch(AuthorizationException $e){if($this->gate->user()===null){wp_safe_redirect(home_url('/cif/login'));exit;}$this->forbidden($e->getMessage());}
    }
    private function forbidden(string $message):never{$this->view->render('error/forbidden',['title'=>'Acesso negado','message'=>$message,'currentUser'=>$this->gate->user()],403);}
    private function notFound(string $message):never{$this->view->render('error/not-found',['title'=>'Artefato não encontrado','message'=>$message,'currentUser'=>$this->gate->user()],404);}
}
