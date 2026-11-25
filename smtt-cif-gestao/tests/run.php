<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Bootstrap/Autoloader.php';

\SMTT\CIF\Gestao\Bootstrap\Autoloader::register(
    'SMTT\\CIF\\Gestao\\',
    dirname(__DIR__) . '/src'
);

use SMTT\CIF\Gestao\Audit\AuditChain;
use SMTT\CIF\Gestao\Domain\Agent\Cpf;
use SMTT\CIF\Gestao\Domain\Emission\EmissionStateMachine;
use SMTT\CIF\Gestao\Domain\Emission\EmissionStatus;
use SMTT\CIF\Gestao\Domain\Emission\PhysicalStatus;
use SMTT\CIF\Gestao\Domain\Emission\ValidationCode;
use SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessProtocol;
use SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessStatus;
use SMTT\CIF\Gestao\Service\CardTemplateRenderer;
use SMTT\CIF\Gestao\Biometry\ImageNormalizer;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Support\Base64Url;

$tests = [];
$test = static function (string $name, callable $fn) use (&$tests): void {
    try {
        $fn();
        $tests[] = [$name, true, ''];
    } catch (Throwable $e) {
        $tests[] = [$name, false, $e->getMessage()];
    }
};
$assert = static function (bool $condition, string $message = 'assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $fn): void {
    try {
        $fn();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('Era esperada uma exceção.');
};


$test('Protocolo CIF de processo é automático e padronizado', static function () use ($assert): void {
    $assert(ProcessProtocol::build(2026, 1) === 'CIF-2026-000001');
    $assert(ProcessProtocol::build(2026, 127) === 'CIF-2026-000127');
    $assert(ProcessProtocol::isValid('CIF-2026-000127'));
    $assert(!ProcessProtocol::isValid('2026/127'));
});

$test('Fluxo simplificado usa Em tramitação como estado operacional', static function () use ($assert): void {
    $assert(ProcessStatus::label(ProcessStatus::EM_ANALISE) === 'Em tramitação');
    $assert(ProcessStatus::label(ProcessStatus::PENDENTE_DOCUMENTACAO) === 'Pendente de regularização');
    $service = (string) file_get_contents(dirname(__DIR__) . '/src/Service/IssuanceProcessService.php');
    $assert(str_contains($service, "'status_codigo' => ProcessStatus::EM_ANALISE"), 'Novo processo deve nascer em tramitação.');
});

$test('Extravio não possui carência automática de 45 dias', static function () use ($assert): void {
    $service = (string) file_get_contents(dirname(__DIR__) . '/src/Service/IssuanceProcessService.php');
    $emission = (string) file_get_contents(dirname(__DIR__) . '/src/Service/EmissionService.php');
    $form = (string) file_get_contents(dirname(__DIR__) . '/templates/processes/form.php');
    $assert(!str_contains($service, "DateInterval('P45D')"));
    $assert(!str_contains($emission, 'data mínima para geração da nova CIF'));
    $assert(!str_contains($form, '+45 dias'));
});

$test('Aprovação pode registrar conferência biográfica no mesmo ato', static function () use ($assert): void {
    $service = (string) file_get_contents(dirname(__DIR__) . '/src/Service/IssuanceProcessService.php');
    $view = (string) file_get_contents(dirname(__DIR__) . '/templates/processes/show.php');
    $assert(str_contains($service, "'origem' => 'APROVACAO_PROCESSO'"));
    $assert(str_contains($view, 'Aprovar e liberar emissão'));
    $assert(str_contains($view, 'metodo_validacao'));
    $assert(str_contains($view, 'titular_confirmou'));
});

$test('Código CIF segue AJU##SEBR@@@@', static function () use ($assert): void {
    $assert(ValidationCode::build(1, 1) === 'AJU01SEBR0001');
    $assert(ValidationCode::build(2, 185) === 'AJU02SEBR0185');
    $assert(ValidationCode::build(99, 9999) === 'AJU99SEBR9999');
    $assert(ValidationCode::isValid('AJU02SEBR0185'));
});

$test('Código CIF rejeita ciclo/sequencial fora do limite', static function () use ($throws): void {
    $throws(static fn() => ValidationCode::build(0, 1));
    $throws(static fn() => ValidationCode::build(1, 0));
    $throws(static fn() => ValidationCode::build(100, 1));
    $throws(static fn() => ValidationCode::build(1, 10000));
});

$test('Base64URL preserva token de 256 bits', static function () use ($assert): void {
    $bytes = str_repeat("\xA5", 32);
    $encoded = Base64Url::encode($bytes);
    $assert(strlen($encoded) === 43, 'Token Base64URL de 32 bytes deve possuir 43 caracteres sem padding.');
    $assert(Base64Url::decode($encoded) === $bytes);
});

$test('CPF válido é normalizado e mascarado', static function () use ($assert): void {
    $cpf = Cpf::fromInput('529.982.247-25');
    $assert($cpf->digits() === '52998224725');
    $assert($cpf->masked() === '***.***.***-25');
});

$test('Máquina de estados aceita emissão pendente → ativa/encerrada', static function (): void {
    EmissionStateMachine::assertValidityTransition(EmissionStatus::PENDENTE_ATIVACAO, EmissionStatus::ATIVA);
    EmissionStateMachine::assertValidityTransition(EmissionStatus::PENDENTE_ATIVACAO, EmissionStatus::ENCERRADA);
    EmissionStateMachine::assertPhysicalTransition(PhysicalStatus::EM_GUARDA_SMTT, PhysicalStatus::ENTREGUE);
    EmissionStateMachine::assertPhysicalTransition(PhysicalStatus::EM_GUARDA_SMTT, PhysicalStatus::INUTILIZADA);
});

$test('Máquina de estados bloqueia reativação de emissão encerrada', static function () use ($throws): void {
    $throws(static fn() => EmissionStateMachine::assertValidityTransition(EmissionStatus::ENCERRADA, EmissionStatus::ATIVA));
});

$test('ADMIN_CIF possui autoridade integral dentro do plugin', static function () use ($assert): void {
    $user = new CifUser(1, '00000000-0000-4000-8000-000000000001', null, 'admin', 'Admin', 'admin@smtt.local', 'RH', true, false, null, ['ADMIN_CIF'], []);
    $assert($user->isSystemAdministrator());
    $assert($user->can('cif.qualquer.permissao') === true);
});

$test('Consulta não recebe capacidade não concedida', static function () use ($assert): void {
    $user = new CifUser(2, '00000000-0000-4000-8000-000000000002', null, 'consulta', 'Consulta', 'consulta@smtt.local', 'RH', true, false, null, ['CONSULTA_CIF'], ['cif.agente.visualizar']);
    $assert($user->can('cif.agente.visualizar'));
    $assert(!$user->can('cif.agente.excluir'));
});

$test('Hash de auditoria é determinístico e detecta alteração', static function () use ($assert): void {
    $previous = hash('sha256', 'genesis', true);
    $record = [
        'uuid'=>'00000000-0000-4000-8000-000000000010',
        'ocorrido_em'=>'2026-09-20 20:00:00.123456',
        'usuario_cif_id'=>1,
        'actor_wp_user_id'=>1,
        'actor_login'=>'admin',
        'actor_nome'=>'Admin',
        'operacao'=>'TESTE',
        'entidade_tipo'=>'teste',
        'entidade_id'=>10,
        'entidade_uuid'=>null,
        'valor_anterior'=>'{"b":2,"a":1}',
        'valor_posterior'=>'{"a":2,"b":2}',
        'justificativa'=>'teste',
        'ip_origem'=>inet_pton('127.0.0.1'),
        'user_agent'=>'test',
        'origem'=>'TEST',
        'request_id'=>null,
    ];
    $hash1 = AuditChain::hash($previous, $record);
    $hash2 = AuditChain::hash($previous, $record);
    $assert(hash_equals($hash1, $hash2));
    $record['justificativa'] = 'alterado';
    $hash3 = AuditChain::hash($previous, $record);
    $assert(!hash_equals($hash1, $hash3));
});


$test('Hash de auditoria normaliza a ordem das chaves JSON', static function () use ($assert): void {
    $previous = hash('sha256', 'genesis-json', true);
    $base = [
        'uuid'=>'00000000-0000-4000-8000-000000000011',
        'ocorrido_em'=>'2026-09-20 20:00:01.000001',
        'usuario_cif_id'=>1,
        'actor_wp_user_id'=>1,
        'actor_login'=>'admin',
        'actor_nome'=>'Admin',
        'operacao'=>'TESTE_JSON',
        'entidade_tipo'=>'teste',
        'entidade_id'=>11,
        'entidade_uuid'=>null,
        'valor_anterior'=>'{"b":2,"a":1}',
        'valor_posterior'=>null,
        'justificativa'=>null,
        'ip_origem'=>null,
        'user_agent'=>'test',
        'origem'=>'TEST',
        'request_id'=>null,
    ];
    $same = $base;
    $same['valor_anterior'] = '{"a":1,"b":2}';
    $assert(hash_equals(AuditChain::hash($previous, $base), AuditChain::hash($previous, $same)));
});

$test('Vínculo WordPress legado não concede autoridade ao usuário CIF', static function () use ($assert): void {
    $user = new CifUser(3, '00000000-0000-4000-8000-000000000003', 3, 'legado', 'Usuário legado', 'legado@smtt.local', 'RH', true, false, null, ['CONSULTA_CIF'], []);
    $assert(!$user->isSystemAdministrator());
    $assert(!$user->can('cif.sincronizacao.configurar'));
});

$test('Máquina física bloqueia reutilização depois de inutilização', static function () use ($throws): void {
    $throws(static fn() => EmissionStateMachine::assertPhysicalTransition(PhysicalStatus::INUTILIZADA, PhysicalStatus::ENTREGUE));
});


$test('Template CR80 2026-06 utiliza as artes institucionais PNG reais', static function () use ($assert): void {
    $base = dirname(__DIR__) . '/assets/img/';
    $front = $base . 'cif-card-front-v3.png';
    $back = $base . 'cif-card-back-v3.png';
    $assert(CardTemplateRenderer::TEMPLATE_VERSION === 'CIF-CR80-2026-06');
    $assert(is_file($front) && is_file($back), 'As artes institucionais CR80 precisam acompanhar o plugin.');
    $frontSize = @getimagesize($front);
    $backSize = @getimagesize($back);
    $assert(is_array($frontSize) && $frontSize[0] === 961 && $frontSize[1] === 606, 'Arte frontal fora da matriz institucional 961x606.');
    $assert(is_array($backSize) && $backSize[0] === 961 && $backSize[1] === 606, 'Arte traseira fora da matriz institucional 961x606.');
    $assert(($frontSize['mime'] ?? '') === 'image/png' && ($backSize['mime'] ?? '') === 'image/png', 'As artes devem permanecer em PNG.');
});


$test('Frente da CIF usa organização vertical e mascara CPF apenas na arte', static function () use ($assert): void {
    $renderer = (string) file_get_contents(dirname(__DIR__) . '/src/Service/CardTemplateRenderer.php');
    $assert(str_contains($renderer, '.front-name{left:31.5%;top:46.6%'));
    $assert(str_contains($renderer, '.front-cpf{left:31.5%;top:61.0%'));
    $assert(str_contains($renderer, '.front-blood{left:55.8%;top:61.0%'));
    $assert(str_contains($renderer, '.front-admission{left:31.5%;top:75.2%'));
    $assert(str_contains($renderer, '.front-registration{left:9.7%;top:87.2%'));

    $method = new ReflectionMethod(CardTemplateRenderer::class, 'maskCardCpf');
    $method->setAccessible(true);
    $masked = $method->invoke(new CardTemplateRenderer(), '025.318.645-55');
    $assert($masked === '025.***.***-55', 'CPF da arte deve ocultar os seis dígitos centrais.');
});

$test('Template da carteira não desenha caixas nos dados e trata assinatura como transparência derivada', static function () use ($assert): void {
    $renderer = (string) file_get_contents(dirname(__DIR__) . '/src/Service/CardTemplateRenderer.php');
    $assert(str_contains($renderer, 'background:transparent!important;border:0!important;outline:0!important;box-shadow:none!important'));
    $assert(str_contains($renderer, 'transparentSignaturePng'));
    $assert(str_contains($renderer, 'imagecolorallocatealpha'));
    $assert(str_contains($renderer, 'Recorta o excesso de papel/transparência'));
    $assert(str_contains($renderer, 'data-clean-signature'));
    $assert(str_contains($renderer, "toDataURL('image/png')"));
});

$test('Qualidade biométrica é validada por pixels e não por DPI obrigatório', static function () use ($assert, $throws): void {
    $normalizer = new ImageNormalizer();
    $normalizer->validateDimensions('photo', 480, 640);
    $normalizer->validateDimensions('signature', 600, 120);
    $throws(static fn() => $normalizer->validateDimensions('photo', 200, 300));
    $throws(static fn() => $normalizer->validateDimensions('signature', 200, 40));
    $template = (string) file_get_contents(dirname(__DIR__) . '/templates/biometry/manage.php');
    $assert(!str_contains($template, 'name="foto_dpi"'), 'O formulário não deve exigir DPI da fotografia.');
    $assert(!str_contains($template, 'name="assinatura_dpi"'), 'O formulário não deve exigir DPI da assinatura.');
});

$test('Preview de carteira existe sem abrir tentativa de impressão', static function () use ($assert): void {
    $router = (string) file_get_contents(dirname(__DIR__) . '/src/Web/Router.php');
    $controller = (string) file_get_contents(dirname(__DIR__) . '/src/Web/Controller/EmissionArtifactController.php');
    $assert(str_contains($router, '/visualizar'));
    $assert(str_contains($controller, 'function preview'));
});


$test('Todas as permissões declaradas possuem catálogo de migration', static function () use ($assert): void {
    $reflection = new ReflectionClass(\SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes::class);
    $permissions = array_values($reflection->getConstants());
    $migrationText = '';
    foreach (glob(dirname(__DIR__) . '/migrations/*.php') ?: [] as $file) {
        $migrationText .= "\n" . (string)file_get_contents($file);
    }
    foreach ($permissions as $permission) {
        $assert(str_contains($migrationText, (string)$permission), 'Permissão sem catálogo de migration: ' . $permission);
    }
});

$test('Métodos despachados pelo Router existem nos controllers lazy', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $router = (string)file_get_contents($root . '/src/Web/Router.php');
    $container = (string)file_get_contents($root . '/src/Bootstrap/Container.php');

    preg_match_all("/'([A-Za-z0-9_]+)'\\s*=>\\s*fn\\(\\):\\s*([A-Za-z0-9_]+Controller)/", $container, $factoryRows, PREG_SET_ORDER);
    $factories = [];
    foreach ($factoryRows as $row) {
        $factories[$row[1]] = 'SMTT\\CIF\\Gestao\\Web\\Controller\\' . $row[2];
    }
    $assert($factories !== [], 'Factories lazy dos controllers não foram localizadas.');

    preg_match_all("/controller\\('([A-Za-z0-9_]+)'\\)->([A-Za-z0-9_]+)\\(/", $router, $calls, PREG_SET_ORDER);
    $assert($calls !== [], 'Chamadas lazy de rota não foram localizadas.');
    foreach ($calls as $call) {
        $key = $call[1];
        $method = $call[2];
        $assert(isset($factories[$key]), 'Factory de controller ausente: ' . $key);
        $fqcn = $factories[$key];
        $assert(class_exists($fqcn), 'Controller não carregado: ' . $fqcn);
        $assert(method_exists($fqcn, $method), sprintf('Método de rota inexistente: %s::%s', $fqcn, $method));
    }
});

$test('Descarte pré-emissão exige justificativa e bloqueia quando há CIF emitida', static function () use ($assert): void {
    $service = file_get_contents(dirname(__DIR__) . '/src/Service/AgentService.php');
    $repo = file_get_contents(dirname(__DIR__) . '/src/Repository/AgentRepository.php');
    $assert(is_string($service) && str_contains($service, 'discardBeforeFirstEmission'));
    $assert(str_contains($service, 'hasIssuedEmission'));
    $assert(str_contains($service, 'AGENTE_CADASTRO_DESCARTADO_PRE_EMISSAO'));
    $assert(str_contains($service, '10') && str_contains($service, '500'));
    $assert(is_string($repo) && str_contains($repo, 'discardPreEmissionAgent'));
    $assert(str_contains($repo, 'DELETE FROM processos_emissao WHERE agente_id = ?'));
    $assert(str_contains($repo, 'DELETE FROM coletas_biometricas WHERE agente_id = ?'));
});

$test('Tela administrativa só libera descarte enquanto não houver emissão', static function () use ($assert): void {
    $show = file_get_contents(dirname(__DIR__) . '/templates/agents/show.php');
    $form = file_get_contents(dirname(__DIR__) . '/templates/agents/form.php');
    $assert(is_string($show) && str_contains($show, "emissoes_total"));
    $assert(str_contains($show, 'Descartar cadastro e preparação'));
    $assert(is_string($form) && str_contains($form, "dependencies['emissoes']"));
    $assert(str_contains($form, 'Exclusão bloqueada após emissão'));
});


$test('Projeção pública inclui data de admissão e reconciliação cobre o novo campo', static function () use ($assert): void {
    $builder = file_get_contents(dirname(__DIR__) . '/src/Sync/ProjectionPayloadBuilder.php');
    $repo = file_get_contents(dirname(__DIR__) . '/src/Repository/EmissionRepository.php');
    $reconcile = file_get_contents(dirname(__DIR__) . '/src/Sync/ReconciliationService.php');
    $assert(is_string($builder) && str_contains($builder, "'admission_date'"));
    $assert(is_string($builder) && str_contains($builder, 'data_admissao'));
    $assert(is_string($repo) && str_contains($repo, 's.data_admissao'));
    $assert(is_string($reconcile) && str_contains($reconcile, "'admission_date'"));
});


$test('Autenticação operacional CIF é independente do WordPress', static function () use ($assert): void {
    $current = (string) file_get_contents(dirname(__DIR__) . '/src/Security/CurrentUser.php');
    $login = (string) file_get_contents(dirname(__DIR__) . '/src/Web/Controller/LoginController.php');
    $assert(str_contains($current, 'SessionManager'));
    $assert(!str_contains($current, 'wp_get_current_user'));
    $assert(!str_contains($current, 'is_user_logged_in'));
    $assert(!str_contains($login, 'wp_signon'));
    $assert(!str_contains($login, 'wp_logout'));
});

$test('Migration de autenticação própria cria identidade e sessões no smtt_cif', static function () use ($assert): void {
    $migration = (string) file_get_contents(dirname(__DIR__) . '/migrations/20260924_025_native_cif_auth.php');
    foreach (['senha_hash', 'setor', 'deve_trocar_senha', 'CREATE TABLE IF NOT EXISTS sessoes_cif', 'token_hash BINARY(32)'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migration sem estrutura esperada: ' . $needle);
    }
});

$test('Usuário CIF nativo mantém identidade e setor sem wp_user obrigatório', static function () use ($assert): void {
    $user = new CifUser(10, '00000000-0000-4000-8000-000000000010', null, 'operador.rh', 'Operador RH', 'operador@smtt.local', 'Recursos Humanos', true, true, null, ['EDITOR_CIF'], ['cif.agente.visualizar']);
    $assert($user->wpUserId === null);
    $assert($user->department === 'Recursos Humanos');
    $assert($user->mustChangePassword === true);
    $assert(!$user->isSystemAdministrator());
});

$test('Área CIF usa CSRF próprio e não nonces de sessão WordPress', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $files = array_merge(glob($root . '/templates/**/*.php') ?: [], glob($root . '/templates/*.php') ?: [], glob($root . '/src/Web/Controller/*.php') ?: []);
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        $assert(!str_contains($source, 'wp_nonce_field('), 'Nonce WordPress remanescente em ' . basename($file));
        $assert(!str_contains($source, 'wp_verify_nonce('), 'Verificação WordPress remanescente em ' . basename($file));
    }
    $csrf = (string) file_get_contents($root . '/src/Security/CsrfToken.php');
    $assert(str_contains($csrf, 'hash_hmac'));
});

$test('Gestão de usuários CIF oferece cadastro próprio e relatório de autoria', static function () use ($assert): void {
    $view = (string) file_get_contents(dirname(__DIR__) . '/templates/security/users.php');
    $report = (string) file_get_contents(dirname(__DIR__) . '/src/Repository/ReportRepository.php');
    foreach (['login', 'email', 'setor', 'password', 'ADMIN_CIF', 'EDITOR_CIF', 'CONSULTA_CIF'] as $needle) {
        $assert(str_contains($view, $needle), 'Tela de usuários sem campo/perfil: ' . $needle);
    }
    $assert(str_contains($report, 'atividade_usuarios'));
    $assert(str_contains($report, 'actor_login'));
});


$test('Troca de senha usa superfície do login sem sidebar e retorna ao login', static function () use ($assert): void {
    $template = (string) file_get_contents(dirname(__DIR__) . '/templates/security/change-password.php');
    $controller = (string) file_get_contents(dirname(__DIR__) . '/src/Web/Controller/PasswordController.php');
    $assert(str_contains($template, 'cif-login-page'));
    $assert(str_contains($template, 'cif-login-layout'));
    $assert(!str_contains($template, 'templates/layout/header.php'));
    $assert(!str_contains($template, 'cif-sidebar'));
    $assert(str_contains($template, 'Após a alteração, sua sessão será encerrada'));
    $assert(str_contains($controller, "password_changed"));
    $assert(str_contains($controller, "home_url('/cif/login')"));
});

$test('Modal de usuários usa campos e seções visuais padronizados', static function () use ($assert): void {
    $template = (string) file_get_contents(dirname(__DIR__) . '/templates/security/users.php');
    $css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/app.css');
    foreach (['type="text" name="name"', 'type="text" name="login"', 'type="text" name="department"', 'cif-user-modal__section', 'cif-user-modal__section-head'] as $needle) {
        $assert(str_contains($template, $needle), 'Tela de usuários sem padrão esperado: ' . $needle);
    }
    $assert(str_contains($css, '.cif-user-modal__section'));
    $assert(str_contains($css, '.cif-field input:not([type])'));
});


$test('Listagens operacionais usam filtros simples, ordenação por coluna e paginação no rodapé', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        'agents/index.php',
        'processes/index.php',
        'emissions/index.php',
        'occurrences/index.php',
        'occurrences/recollections.php',
    ] as $relative) {
        $source = (string)file_get_contents($root . '/templates/' . $relative);
        $assert(str_contains($source, 'cif-sort-link'), 'Listagem sem ordenação por cabeçalho: ' . $relative);
        $assert(str_contains($source, 'templates/layout/list-footer.php'), 'Listagem sem rodapé padronizado: ' . $relative);
        $assert(!str_contains($source, 'name="direcao"'), 'Direção ainda exposta como seletor: ' . $relative);
        $assert(!str_contains($source, 'name="por_pagina"'), 'Quantidade por página ainda está no filtro superior: ' . $relative);
    }
    $agents = (string)file_get_contents($root . '/templates/agents/index.php');
    $processes = (string)file_get_contents($root . '/templates/processes/index.php');
    $assert(!str_contains($agents, 'admissao_de') && !str_contains($agents, 'atualizado_ate'));
    $assert(!str_contains($processes, 'criado_de') && !str_contains($processes, 'criado_ate'));
    $footer = (string)file_get_contents($root . '/templates/layout/list-footer.php');
    $assert(str_contains($footer, 'data-cif-auto-submit'));
    $assert(str_contains($footer, '$pages <= 7'));
});

$test('Inicialização do CIF é lazy e não conecta o banco dedicado fora das rotas /cif', static function () use ($assert): void {
    $router = (string)file_get_contents(dirname(__DIR__) . '/src/Web/Router.php');
    $container = (string)file_get_contents(dirname(__DIR__) . '/src/Bootstrap/Container.php');
    $assert(str_contains($router, 'currentUserFactory'));
    $assert(str_contains($router, "controllerFactories"));
    $pathPos = strpos($router, '$path = $this->relativePath()');
    $userPos = strpos($router, '$authenticated = $this->currentUser()->get()');
    $assert(is_int($pathPos) && is_int($userPos) && $pathPos < $userPos, 'Identidade CIF está sendo resolvida antes de confirmar a rota /cif.');
    $assert(str_contains($container, 'fn(): CurrentUser => $this->currentUser()'));
    $assert(str_contains($container, "'agents' => fn(): AgentController"));
});

$test('Autorização corrente reduz round-trips e índices de listagem possuem migration própria', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $authorization = (string)file_get_contents($root . '/src/Repository/AuthorizationRepository.php');
    $migration = (string)file_get_contents($root . '/migrations/20260924_026_list_performance.php');
    $assert(str_contains($authorization, 'authorizationSnapshot'));
    $assert(substr_count($authorization, 'UNION ALL') >= 2);
    foreach ([
        'idx_processos_criado_id',
        'idx_emissoes_emitida_id',
        'idx_ocorrencias_data_id',
        'idx_recolhimentos_criado_id',
    ] as $index) {
        $assert(str_contains($migration, $index), 'Índice de performance ausente: ' . $index);
    }
});



$test('Relatórios possuem navegação por tipo e relatório contextual imprimível', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $view = (string)file_get_contents($root . '/templates/reports/index.php');
    $repo = (string)file_get_contents($root . '/src/Repository/ReportRepository.php');
    foreach (['Agentes','Processos','Emissões CIF','Ocorrências','Recolhimentos','Atividade dos usuários'] as $label) {
        $controller = (string)file_get_contents($root . '/src/Web/Controller/ReportController.php');
        $assert(str_contains($controller, $label), 'Tipo de relatório ausente: ' . $label);
    }
    $assert(str_contains($view, 'cif-report-tabs'));
    $assert(str_contains($view, 'cif-report-workspace'));
    $assert(str_contains($view, 'aria-current="page"'));
    $assert(str_contains($view, 'cif-report-toolbar'));
    $assert(str_contains($view, 'cif-report-action'));
    $assert(str_contains($view, 'Ver relatório'));
    $assert(str_contains($view, 'data-report-print'));
    $assert(str_contains($view, 'cif-report-modal'));
    $assert(str_contains($repo, 'AS __uuid'));
});

$test('Relatórios usam filtro de data único, ordenação por coluna e paginação padronizada', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $view = (string)file_get_contents($root . '/templates/reports/index.php');
    $controller = (string)file_get_contents($root . '/src/Web/Controller/ReportController.php');
    $repo = (string)file_get_contents($root . '/src/Repository/ReportRepository.php');
    $assert(str_contains($view, 'name="data"'));
    $assert(!str_contains($view, 'name="de"') && !str_contains($view, 'name="ate"'));
    $assert(str_contains($view, 'cif-sort-link'));
    $assert(str_contains($view, 'templates/layout/list-footer.php'));
    $assert(str_contains($controller, "'ordem'"));
    $assert(str_contains($controller, "'direcao'"));
    $assert(str_contains($repo, "DATE_ADD(?, INTERVAL 1 DAY)"));
});

$test('Atividade dos usuários oferece detalhes de auditoria e vínculo navegável quando possível', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $view = (string)file_get_contents($root . '/templates/reports/index.php');
    $repo = (string)file_get_contents($root . '/src/Repository/ReportRepository.php');
    foreach (['Ver registro','Detalhes','Valor anterior','Valor posterior','Request ID','Integridade'] as $needle) {
        $assert(str_contains($view, $needle), 'Detalhe de atividade ausente: ' . $needle);
    }
    foreach (['valor_anterior','valor_posterior','justificativa','ip_origem_texto','record_hash'] as $needle) {
        $assert(str_contains($repo, $needle), 'Campo de auditoria ausente no relatório: ' . $needle);
    }
});

$test('Abertura a partir de relatório preserva caminho de retorno nas telas de detalhe', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        'agents/show.php',
        'processes/show.php',
        'emissions/show.php',
        'occurrences/show.php',
        'occurrences/recollection-show.php',
    ] as $relative) {
        $source = (string)file_get_contents($root . '/templates/' . $relative);
        $assert(str_contains($source, 'wp_validate_redirect'));
        $assert(str_contains($source, 'Voltar ao relatório'));
    }
});



$test('Ciclo CIF é individual por agente/processo e sequencial final permanece global', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $processService = (string)file_get_contents($root . '/src/Service/IssuanceProcessService.php');
    $processRepo = (string)file_get_contents($root . '/src/Repository/IssuanceProcessRepository.php');
    $emissionService = (string)file_get_contents($root . '/src/Service/EmissionService.php');
    $assert(str_contains($processService, 'nextAgentCycle'));
    $assert(str_contains($processRepo, 'controle_ciclo_agente'));
    $assert(str_contains($processRepo, 'ultimo_ciclo'));
    $assert(str_contains($emissionService, "process['ciclo_numero']"));
    $assert(str_contains($emissionService, 'ValidationCode::build($cycleNumber, $sequence)'));
    $assert(str_contains($emissionService, "control['ultimo_sequencial']"));
    $assert(!str_contains($emissionService, 'ensureInitialCycle('), 'Emissão ainda depende de ciclo global legado.');
});

$test('Migration 027 preserva códigos emitidos e cria controle de ciclo por agente', static function () use ($assert): void {
    $migration = (string)file_get_contents(dirname(__DIR__) . '/migrations/20260924_027_agent_cycles.php');
    foreach (['ciclo_numero TINYINT UNSIGNED', 'CREATE TABLE IF NOT EXISTS controle_ciclo_agente', 'ultimo_ciclo', 'ciclo_numero_agente', "SUBSTRING(e.codigo_validacao, 4, 2)"] as $needle) {
        $assert(str_contains($migration, $needle), 'Migration de ciclos sem estrutura esperada: ' . $needle);
    }
    $assert(str_contains($migration, 'MODIFY ciclo_id BIGINT UNSIGNED NULL'));
});

$test('Ocorrências e recolhimentos saíram da navegação operacional e permanecem em Relatórios', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $header = (string)file_get_contents($root . '/templates/layout/header.php');
    $dashboard = (string)file_get_contents($root . '/templates/dashboard/index.php');
    $reports = (string)file_get_contents($root . '/src/Web/Controller/ReportController.php');
    $assert(!str_contains($header, "activeNav === 'occurrences'"));
    $assert(!str_contains($header, "activeNav === 'recollections'"));
    $assert(!str_contains($dashboard, "'/cif/ocorrencias'"));
    $assert(!str_contains($dashboard, "'/cif/recolhimentos'"));
    $assert(str_contains($reports, "'ocorrencias' => 'Ocorrências'"));
    $assert(str_contains($reports, "'recolhimentos' => 'Recolhimentos'"));
});

$test('Ficha do agente não duplica ocorrências e Emissão centraliza o ciclo de vida da CIF', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $agent = (string)file_get_contents($root . '/templates/agents/show.php');
    $emission = (string)file_get_contents($root . '/templates/emissions/show.php');
    $assert(!str_contains($agent, 'id="ocorrencias"'));
    $assert(!str_contains($agent, '>Ocorrências<'));
    foreach (['Histórico e eventos da CIF', 'Registrar ocorrência', 'Registrar recolhimento', 'Ocorrências desta emissão', 'Recolhimentos / inutilizações', 'Linha do tempo técnica'] as $needle) {
        $assert(str_contains($emission, $needle), 'Emissão sem bloco integrado esperado: ' . $needle);
    }
});

$test('Descarte pré-emissão também remove o contador de ciclo preparatório do agente', static function () use ($assert): void {
    $repo = (string)file_get_contents(dirname(__DIR__) . '/src/Repository/AgentRepository.php');
    $assert(str_contains($repo, 'DELETE FROM controle_ciclo_agente WHERE agente_id = ?'));
});


$test('Conferência do processo foi incorporada à aprovação com dados completos da CIF', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $view = (string)file_get_contents($root . '/templates/processes/show.php');
    $repo = (string)file_get_contents($root . '/src/Repository/IssuanceProcessRepository.php');
    $assert(!str_contains($view, '<h2>Conferência dos dados</h2>'), 'Card independente de conferência ainda existe.');
    foreach (['Aprovar e liberar emissão', 'Nome completo', 'Matrícula', 'CPF', 'Situação funcional', 'Naturalidade', 'Data de nascimento', 'Tipo sanguíneo', 'Data de admissão', 'Versão cadastral', 'Origem do cadastro', 'Biometria atual'] as $needle) {
        $assert(str_contains($view, $needle), 'Revisão de aprovação sem campo esperado: ' . $needle);
    }
    $assert(str_contains($view, 'cif-process-review__photo'));
    $assert(str_contains($repo, 'currentBiometrySummary'));
    $assert(str_contains($repo, 'matricula_snapshot AS matricula_biografica'));
});

$test('Página do processo mantém somente indicador da conferência com autoria e data', static function () use ($assert): void {
    $view = (string)file_get_contents(dirname(__DIR__) . '/templates/processes/show.php');
    foreach (['cif-process-review-status', 'Conferida em', 'Responsável:', 'Conferir dados agora'] as $needle) {
        $assert(str_contains($view, $needle), 'Indicador de conferência incompleto: ' . $needle);
    }
});

$test('Pendência, indeferimento e cancelamento possuem decisões estruturadas e auditáveis', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $view = (string)file_get_contents($root . '/templates/processes/show.php');
    $service = (string)file_get_contents($root . '/src/Service/IssuanceProcessService.php');
    foreach (['Tipo de pendência', 'Descrição da pendência', 'Prazo para regularização', 'Motivo do indeferimento', 'Fundamentação', 'Motivo do cancelamento'] as $needle) {
        $assert(str_contains($view, $needle), 'Decisão administrativa sem campo esperado: ' . $needle);
    }
    foreach (['PENDING_REASONS', 'DENIAL_REASONS', 'CANCELLATION_REASONS', "'motivo_codigo'", "'motivo_label'", "'prazo_regularizacao'"] as $needle) {
        $assert(str_contains($service, $needle), 'Metadado estruturado ausente no serviço: ' . $needle);
    }
});


$test('Produção da carteira abre em nova aba e elimina navegação operacional duplicada', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $view = (string)file_get_contents($root . '/templates/emissions/show.php');
    $controller = (string)file_get_contents($root . '/src/Web/Controller/EmissionArtifactController.php');
    $assert(str_contains($view, '>Produzir carteira<'));
    $assert(str_contains($view, 'target="_blank"'));
    $assert(str_contains($view, '>Visualizar carteira<'));
    $assert(!str_contains($view, 'Gerar e visualizar carteira'));
    $assert(!str_contains($view, 'Abrir arte para imprimir / salvar PDF'));
    $assert(str_contains($show, 'Confirmar produção concluída'));
    $assert(!str_contains($controller, 'Registrar falha na produção'));
    $assert(str_contains($controller, "form-action 'self'"));
});

$test('Confirmação de produção atualiza a aba original sem executar ativação na arte', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $view = (string)file_get_contents($root . '/templates/emissions/show.php');
    $controller = (string)file_get_contents($root . '/src/Web/Controller/EmissionArtifactController.php');
    $js = (string)file_get_contents($root . '/assets/js/app.js');
    $assert(str_contains($controller, "return_mode\" value=\"production_tab"));
    $assert(str_contains($controller, 'smtt-cif-production-updated'));
    $assert(str_contains($controller, 'window.opener.postMessage'));
    $assert(str_contains($js, "data-cif-production-watch"));
    $assert(str_contains($js, "window.location.reload()"));
    $assert(str_contains($view, 'Nenhuma ativação ocorre nessa aba.'));
    $assert(str_contains($view, 'Registrar entrega e ativar CIF'));
});

$test('Produção reaproveita tentativa aberta e evita tentativas concorrentes', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $repo = (string)file_get_contents($root . '/src/Repository/ArtifactRepository.php');
    $service = (string)file_get_contents($root . '/src/Service/CardArtifactService.php');
    $view = (string)file_get_contents($root . '/templates/emissions/show.php');
    $assert(str_contains($repo, 'openPrintAttemptForEmission'));
    $assert(str_contains($service, 'openPrintAttemptForEmission'));
    $assert(str_contains($service, "openPrintAttemptForEmission"));
    $assert(str_contains($service, "['NAO_PRODUZIDA','EM_GUARDA_SMTT']"));
    $assert(str_contains($view, 'Continuar produção'));
    $assert(str_contains($view, 'Nova tentativa de produção'));
    $assert(!str_contains($view, 'name="resultado" required'));
});



$test('Editor CIF recebe Relatórios e preparação de emissão permanece exclusiva do administrador', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string)file_get_contents($root . '/migrations/20260924_029_profile_visibility_cleanup.php');
    $controller = (string)file_get_contents($root . '/src/Web/Controller/EmissionController.php');
    $reports = (string)file_get_contents($root . '/src/Web/Controller/ReportController.php');
    $assert(str_contains($migration, "p.codigo = 'EDITOR_CIF'"));
    $assert(str_contains($migration, "perm.codigo = 'cif.relatorio.visualizar'"));
    $assert(str_contains($migration, "'cif.numeracao.visualizar', 'cif.signatario.visualizar'"));
    $assert(str_contains($controller, "'canSetup' => \$user->isSystemAdministrator()"));
    $assert(str_contains($controller, '$user = $this->requireAdmin();'));
    $assert(str_contains($reports, 'if ($user->isSystemAdministrator())'));
});

$test('Sincronização manual permanece como contingência com reparo seletivo', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $view = (string)file_get_contents($root . '/templates/sync/index.php');
    $controller = (string)file_get_contents($root . '/src/Web/Controller/SyncController.php');
    $router = (string)file_get_contents($root . '/src/Web/Router.php');
    foreach (['Sincronização automática ativa', 'Executar agora', 'Ferramentas avançadas', 'Reconstruir tudo'] as $needle) {
        $assert(str_contains($view, $needle), 'Tela de sincronização sem elemento esperado: ' . $needle);
    }
    $assert(str_contains($router, "/cif/sincronizacao/sincronizar"));
    $assert(str_contains($controller, 'public function syncNow(): never'));
    $assert(str_contains($controller, '$this->heartbeat->send();'));
    $assert(str_contains($controller, '$this->worker->run(100, false);'));
    $assert(str_contains($controller, '$this->reconciliation->reconcile();'));
    $assert(str_contains($controller, "reenqueued'] ?? 0"));
});

$test('Sincronização automática usa cron, pequenos lotes e lock global', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $scheduler = (string)file_get_contents($root . '/src/Sync/SyncScheduler.php');
    $repo = (string)file_get_contents($root . '/src/Repository/SyncRepository.php');
    $reconciliation = (string)file_get_contents($root . '/src/Sync/ReconciliationService.php');
    foreach (['smtt_cif_sync_queue_tick', 'smtt_cif_sync_reconcile_tick', "'interval' => 60", "15 * 60", '$this->worker()->run(25, false)', 'reconcileAutomaticSlice(200)'] as $needle) {
        $assert(str_contains($scheduler, $needle), 'Agendador automático sem regra esperada: ' . $needle);
    }
    $assert(str_contains($repo, 'GET_LOCK(?, 0)'));
    $assert(str_contains($repo, 'auto_reconcile_cursor'));
    $assert(str_contains($reconciliation, 'public function reconcileAutomaticSlice(int $limit = 200): array'));
    $assert(str_contains($reconciliation, 'projectionStatesAfter($cursor, $limit)'));
});

$test('Registro do cron não reabre banco CIF em páginas WordPress alheias', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $container = (string)file_get_contents($root . '/src/Bootstrap/Container.php');
    $scheduler = (string)file_get_contents($root . '/src/Sync/SyncScheduler.php');
    $assert(str_contains($container, 'fn(): SyncRepository => $this->syncRepository()'));
    $assert(str_contains($container, 'fn(): SyncWorker => $this->syncWorker()'));
    $assert(str_contains($scheduler, 'private readonly Closure $repositoryFactory'));
    $assert(str_contains($scheduler, 'quando um tick do cron realmente executa trabalho'));
});

$test('Migration registra estado da automação e cursor de reconciliação', static function () use ($assert): void {
    $migration = (string)file_get_contents(dirname(__DIR__) . '/migrations/20260924_030_automatic_sync_scheduler.php');
    foreach (['ultimo_worker_automatico_em', 'ultimo_worker_automatico_ok_em', 'ultima_reconciliacao_automatica_em', 'auto_reconcile_cursor', 'ultimo_erro_automatico'] as $column) {
        $assert(str_contains($migration, $column), 'Migration automática sem coluna: ' . $column);
    }
});

$test('Termo de entrega assinado exige método formal e arquivo no backend', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $delivery = (string) file_get_contents($root . '/src/Service/DeliveryService.php');
    $assert(array_keys(\SMTT\CIF\Gestao\Service\DeliveryService::methods()) === ['TERMO_ASSINADO']);
    $assert(str_contains($delivery, 'latestSignedDeliveryTerm'));
    $assert(str_contains($delivery, 'assinaturas_conferidas'));
    $assert(str_contains($delivery, 'storage->read('));
});

$test('Upload de termo assinado é criptografado, validado e auditado', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $service = (string) file_get_contents($root . '/src/Service/SignedDeliveryTermService.php');
    foreach (['is_uploaded_file', 'MAX_BYTES = 10 * 1024 * 1024', 'application/pdf', 'image/jpeg', 'image/png',
              'storeFile(', 'assinaturas_conferidas', 'lockEmissionByUuid', 'TERMO_ENTREGA_ASSINADO_ANEXADO'] as $needle) {
        $assert(str_contains($service, $needle), 'Termo sem proteção: ' . $needle);
    }
});

$test('Termos por emissão têm histórico e migration aditiva', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/migrations/20261013_031_signed_delivery_term.php');
    $repo = (string) file_get_contents($root . '/src/Repository/EmissionRepository.php');
    $assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS emissoes_termos_entrega'));
    $assert(str_contains($migration, 'FOREIGN KEY (emissao_id) REFERENCES emissoes_cif'));
    $assert(str_contains($migration, 'FOREIGN KEY (arquivo_id) REFERENCES arquivos_privados'));
    $assert(str_contains($repo, 'ORDER BY t.id DESC LIMIT 1'));
    $assert(str_contains($repo, 'signedDeliveryTermByFileUuid'));
});

$test('Tela e servidor exigem termo antes de ativar CIF', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $view = (string) file_get_contents($root . '/templates/emissions/show.php');
    $ctrl = (string) file_get_contents($root . '/src/Web/Controller/EmissionController.php');
    $router = (string) file_get_contents($root . '/src/Web/Router.php');
    $assert(str_contains($view, 'enctype="multipart/form-data"'));
    $assert(str_contains($view, 'name="assinaturas_conferidas"'));
    $assert(str_contains($view, 'disabled aria-disabled='));
    $assert(str_contains($ctrl, "'metodo_confirmacao' => 'TERMO_ASSINADO'"));
    $assert(str_contains($ctrl, "PermissionCodes::EMISSAO_ENTREGAR"));
    $assert(str_contains($router, 'termo-assinado/anexar'));
    $assert(str_contains($router, 'signedTermFile'));
});

$test('Upgrade no sistema CIF aplica apenas migrations pendentes', static function () use ($assert): void {
    $plugin = (string) file_get_contents(dirname(__DIR__) . '/src/Bootstrap/Plugin.php');
    $assert(str_contains($plugin, 'ensureInstalledForCifUpdate'));
    $assert(str_contains($plugin, 'self::ensureInstalledAfterUpdate();'));
    $assert(str_contains($plugin, 'smtt_cif_gestao_migrated_version'));
});

$failures = array_filter($tests, static fn(array $row): bool => !$row[1]);
foreach ($tests as [$name, $ok, $message]) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $name . ($message !== '' ? ' — ' . $message : '') . PHP_EOL;
}

echo PHP_EOL . sprintf('%d teste(s), %d falha(s).', count($tests), count($failures)) . PHP_EOL;
exit($failures === [] ? 0 : 1);
