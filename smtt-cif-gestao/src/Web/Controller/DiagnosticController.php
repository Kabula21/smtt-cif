<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use SMTT\CIF\Gestao\Bootstrap\Environment;
use SMTT\CIF\Gestao\Database\MigrationRunner;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Service\AuditIntegrityService;
use SMTT\CIF\Gestao\Service\EmissionSetupService;
use SMTT\CIF\Gestao\Service\SyncConfigService;
use SMTT\CIF\Gestao\Web\View;

final class DiagnosticController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly Environment $environment,
        private readonly MigrationRunner $migrations,
        private readonly EmissionSetupService $emissionSetup,
        private readonly SyncConfigService $syncConfig,
        private readonly AuditIntegrityService $auditIntegrity
    ) {
    }

    public function index(): never
    {
        try {
            $user = $this->gate->requireSystemAdministrator();
        } catch (AuthorizationException $e) {
            if ($this->gate->user() === null) {
                wp_safe_redirect(home_url('/cif/login'));
                exit;
            }
            $this->view->render('error/forbidden', ['title'=>'Acesso negado','message'=>$e->getMessage(),'currentUser'=>$this->gate->user()], 403);
        }

        $checks = [];
        $checks[] = $this->check('PHP 8.1+', PHP_VERSION_ID >= 80100, PHP_VERSION);
        $checks[] = $this->check('Extensão mysqli', extension_loaded('mysqli'), extension_loaded('mysqli') ? 'Carregada' : 'Ausente');
        $checks[] = $this->check('Extensão sodium', extension_loaded('sodium'), extension_loaded('sodium') ? 'Carregada' : 'Ausente');
        $checks[] = $this->check('Extensão JSON', extension_loaded('json'), extension_loaded('json') ? 'Carregada' : 'Ausente');
        $checks[] = ['name'=>'Imagick','ok'=>extension_loaded('imagick'),'level'=>extension_loaded('imagick')?'ok':'info','detail'=>extension_loaded('imagick')?'Carregada':'Opcional; GD/rotinas atuais continuam disponíveis quando aplicável.'];

        $db = $this->environment->database();
        $checks[] = $this->check('Banco oficial CIF', $db['name'] !== '', $db['name'] . ' · ' . $db['credential_source']);
        $secretDir = $this->environment->secretDirectory();
        $secretExists = is_dir($secretDir);
        $secretWritable = $secretExists && is_writable($secretDir);
        $checks[] = [
            'name' => 'Diretório privado',
            'ok' => $secretWritable,
            'level' => $secretWritable ? 'ok' : 'warning',
            'detail' => $secretDir . ($secretExists ? ($secretWritable ? ' · gravável' : ' · sem permissão de escrita') : ' · ainda não existe; confirme a criação/permissões antes de biometria, documentos e chaves')
        ];

        $migrationStatus = $this->migrations->status();
        $allApplied = $migrationStatus !== [] && count(array_filter($migrationStatus, static fn(array $m): bool => !$m['applied'] || !$m['checksum_ok'])) === 0;
        $checks[] = $this->check('Migrations', $allApplied, $allApplied ? count($migrationStatus) . ' migration(s) aplicadas e íntegras' : 'Existem migrations pendentes ou checksum divergente');

        try {
            $setup = $this->emissionSetup->overview();
            $numbering = $setup['numbering'] ?? [];
            $checks[] = ['name'=>'Numeração CIF','ok'=>isset($numbering['ultimo_sequencial']),'level'=>isset($numbering['ultimo_sequencial'])?'ok':'warning','detail'=>isset($numbering['ultimo_sequencial']) ? 'Sequencial global em ' . str_pad((string)$numbering['ultimo_sequencial'],4,'0',STR_PAD_LEFT) . ' · ciclo individual por agente/processo' : 'Controle de numeração não localizado'];
            $checks[] = ['name'=>'Signatário institucional','ok'=>is_array($setup['current_signer'] ?? null),'level'=>is_array($setup['current_signer'] ?? null)?'ok':'warning','detail'=>is_array($setup['current_signer'] ?? null) ? (string)$setup['current_signer']['nome'] : 'Não configurado'];
        } catch (\Throwable $e) {
            $checks[] = ['name'=>'Preparação de emissão','ok'=>false,'level'=>'error','detail'=>$e->getMessage()];
        }

        try {
            $sync = $this->syncConfig->overview();
            $checks[] = ['name'=>'Sincronização pública','ok'=>!empty($sync['enabled']),'level'=>!empty($sync['enabled'])?'ok':'info','detail'=>!empty($sync['enabled']) ? 'Habilitada · ' . (string)$sync['environment'] : 'Desabilitada. Isso é esperado até o plugin público estar instalado e homologado.'];
            $checks[] = ['name'=>'Chave Ed25519','ok'=>is_array($sync['active_key'] ?? null) && !empty($sync['key_consistent']),'level'=>is_array($sync['active_key'] ?? null) && !empty($sync['key_consistent'])?'ok':'info','detail'=>is_array($sync['active_key'] ?? null) ? ((string)$sync['active_key']['key_id'] . (!empty($sync['key_consistent']) ? ' · consistente' : ' · inconsistência de keyring')) : 'Ainda não ativada'];
        } catch (\Throwable $e) {
            $checks[] = ['name'=>'Configuração de sincronização','ok'=>false,'level'=>'warning','detail'=>$e->getMessage()];
        }

        $audit = $this->auditIntegrity->verify();
        $checks[] = ['name'=>'Integridade da auditoria','ok'=>$audit['valid'],'level'=>$audit['valid']?'ok':'warning','detail'=>$audit['message']];

        $blocking = count(array_filter($checks, static fn(array $c): bool => in_array($c['level'], ['error'], true)));
        $warnings = count(array_filter($checks, static fn(array $c): bool => $c['level'] === 'warning'));

        $this->view->render('diagnostics/index', [
            'title' => 'Diagnóstico do Sistema CIF',
            'currentUser' => $user,
            'activeNav' => 'diagnostics',
            'checks' => $checks,
            'blocking' => $blocking,
            'warnings' => $warnings,
            'migrationStatus' => $migrationStatus,
            'audit' => $audit,
            'databaseName' => $db['name'],
        ]);
    }

    /** @return array{name:string,ok:bool,level:string,detail:string} */
    private function check(string $name, bool $ok, string $detail): array
    {
        return ['name'=>$name,'ok'=>$ok,'level'=>$ok?'ok':'error','detail'=>$detail];
    }
}
