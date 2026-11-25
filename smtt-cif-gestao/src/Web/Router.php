<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web;

use SMTT\CIF\Gestao\Security\CurrentUser;
use SMTT\CIF\Gestao\Web\Controller\DashboardController;
use SMTT\CIF\Gestao\Web\Controller\LoginController;
use SMTT\CIF\Gestao\Web\Controller\PasswordController;
use SMTT\CIF\Gestao\Web\Controller\UserAccessController;
use SMTT\CIF\Gestao\Web\Controller\AgentController;
use SMTT\CIF\Gestao\Web\Controller\AgentImportController;
use SMTT\CIF\Gestao\Web\Controller\BiometricController;
use SMTT\CIF\Gestao\Web\Controller\IssuanceProcessController;
use SMTT\CIF\Gestao\Web\Controller\EmissionController;
use SMTT\CIF\Gestao\Web\Controller\EmissionArtifactController;
use SMTT\CIF\Gestao\Web\Controller\OccurrenceController;
use SMTT\CIF\Gestao\Web\Controller\SyncController;
use SMTT\CIF\Gestao\Web\Controller\ReportController;
use SMTT\CIF\Gestao\Web\Controller\AuditController;
use SMTT\CIF\Gestao\Web\Controller\DiagnosticController;

final class Router
{
    /** @var array<string,callable():object> */
    private array $controllerFactories;
    /** @var array<string,object> */
    private array $controllers = [];
    /** @var \Closure():CurrentUser */
    private \Closure $currentUserFactory;
    private ?CurrentUser $resolvedCurrentUser = null;

    /** @param callable():CurrentUser $currentUserFactory @param array<string,callable():object> $controllerFactories */
    public function __construct(
        private readonly SecurityHeaders $securityHeaders,
        callable $currentUserFactory,
        array $controllerFactories
    ) {
        $this->currentUserFactory = \Closure::fromCallable($currentUserFactory);
        $this->controllerFactories = $controllerFactories;
    }

    private function currentUser(): CurrentUser
    {
        if ($this->resolvedCurrentUser !== null) {
            return $this->resolvedCurrentUser;
        }
        $factory = $this->currentUserFactory;
        return $this->resolvedCurrentUser = $factory();
    }

    private function controller(string $name): object
    {
        if (isset($this->controllers[$name])) {
            return $this->controllers[$name];
        }
        $factory = $this->controllerFactories[$name] ?? null;
        if (!is_callable($factory)) {
            throw new \LogicException('Controller CIF não registrado: ' . $name);
        }
        return $this->controllers[$name] = $factory();
    }

    public function register(): void
    {
        // O CIF é uma aplicação própria e não precisa esperar o WordPress montar a query do tema.
        // Despachar no init elimina parse_query, resolução de template e hooks de frontend
        // desnecessários antes de cada tela do sistema.
        add_action('init', [$this, 'dispatch'], 2);
    }

    public function dispatch(): void
    {
        $path = $this->relativePath();
        if ($path === null || ($path !== '/cif' && !str_starts_with($path, '/cif/'))) {
            return;
        }

        $this->securityHeaders->send();

        $authenticated = $this->currentUser()->get();
        if (
            $authenticated !== null
            && $authenticated->mustChangePassword
            && !in_array($path, ['/cif/login', '/cif/logout', '/cif/minha-senha'], true)
        ) {
            wp_safe_redirect(home_url('/cif/minha-senha'));
            exit;
        }

        if ($path === '/cif' || $path === '/cif/') {
            $this->controller('dashboard')->handle();
        }

        if ($path === '/cif/login' || $path === '/cif/login/') {
            $this->controller('login')->handle();
        }

        if ($path === '/cif/logout' || $path === '/cif/logout/') {
            $this->controller('login')->logout();
        }

        if ($path === '/cif/minha-senha' || $path === '/cif/minha-senha/') {
            $this->controller('password')->handle();
        }

        if (preg_match('#^/cif/seguranca/usuarios/([0-9a-fA-F-]{36})/remover$#', $path, $matches) === 1) {
            $this->controller('userAccess')->remove(strtolower($matches[1]));
        }

        if ($path === '/cif/seguranca/usuarios' || $path === '/cif/seguranca/usuarios/') {
            $this->controller('userAccess')->handle();
        }

        if ($path === '/cif/agentes') {
            $this->controller('agents')->index();
        }

        if ($path === '/cif/agentes/exportar') {
            $this->controller('agents')->export();
        }

        if ($path === '/cif/agentes/importar') {
            $this->controller('agentImports')->index();
        }

        if ($path === '/cif/agentes/importar/modelo.csv') {
            $this->controller('agentImports')->csvTemplate();
        }

        if ($path === '/cif/agentes/importar/modelo.xlsx') {
            $this->controller('agentImports')->xlsxTemplate();
        }

        if ($path === '/cif/biometria') {
            $this->controller('biometrics')->index();
        }

        if ($path === '/cif/processos') {
            $this->controller('processes')->index();
        }

        if ($path === '/cif/emissoes') {
            $this->controller('emissions')->index();
        }

        if ($path === '/cif/ocorrencias') {
            $this->controller('occurrences')->index();
        }

        if ($path === '/cif/recolhimentos') {
            $this->controller('occurrences')->recollections();
        }

        if ($path === '/cif/relatorios') {
            $this->controller('reports')->index();
        }

        if ($path === '/cif/relatorios/exportar') {
            $this->controller('reports')->export();
        }

        if ($path === '/cif/auditoria') {
            $this->controller('audit')->index();
        }

        if ($path === '/cif/diagnostico') {
            $this->controller('diagnostics')->index();
        }

        if ($path === '/cif/sincronizacao') {
            $this->controller('sync')->index();
        }

        if ($path === '/cif/sincronizacao/configurar') {
            $this->controller('sync')->saveConfig();
        }

        if ($path === '/cif/sincronizacao/chaves/gerar') {
            $this->controller('sync')->generateKey();
        }

        if ($path === '/cif/sincronizacao/chaves/ativar') {
            $this->controller('sync')->activateKey();
        }

        if ($path === '/cif/sincronizacao/sincronizar') {
            $this->controller('sync')->syncNow();
        }

        if ($path === '/cif/sincronizacao/executar') {
            $this->controller('sync')->run();
        }

        if ($path === '/cif/sincronizacao/heartbeat') {
            $this->controller('sync')->heartbeat();
        }

        if ($path === '/cif/sincronizacao/reconciliar') {
            $this->controller('sync')->reconcile();
        }

        if ($path === '/cif/sincronizacao/reconstruir') {
            $this->controller('sync')->rebuild();
        }

        if (preg_match('#^/cif/sincronizacao/eventos/([0-9a-fA-F-]{36})/reenviar$#', $path, $matches) === 1) {
            $this->controller('sync')->requeue(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/ocorrencias/arquivo/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
            $this->controller('occurrences')->file(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/ocorrencias/nova$#', $path, $matches) === 1) {
            $this->controller('occurrences')->create(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/ocorrencias/([0-9a-fA-F-]{36})/recuperar$#', $path, $matches) === 1) {
            $this->controller('occurrences')->recover(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/ocorrencias/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
            $this->controller('occurrences')->show(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/recolhimentos/novo$#', $path, $matches) === 1) {
            $this->controller('occurrences')->createRecollection(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/recolhimentos/([0-9a-fA-F-]{36})/recolher$#', $path, $matches) === 1) {
            $this->controller('occurrences')->collect(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/recolhimentos/([0-9a-fA-F-]{36})/nao-recolhido$#', $path, $matches) === 1) {
            $this->controller('occurrences')->notCollected(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/recolhimentos/([0-9a-fA-F-]{36})/inutilizar$#', $path, $matches) === 1) {
            $this->controller('occurrences')->invalidate(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/recolhimentos/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
            $this->controller('occurrences')->recollectionShow(strtolower($matches[1]));
        }

        if ($path === '/cif/emissoes/preparacao') {
            $this->controller('emissions')->setup();
        }


        if ($path === '/cif/emissoes/preparacao/signatario') {
            $this->controller('emissions')->createSigner();
        }

        if ($path === '/cif/emissoes/preparacao/url-validacao') {
            $this->controller('emissions')->saveValidationUrl();
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/artefato$#', $path, $matches) === 1) {
            $this->controller('emissionArtifacts')->generate(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/visualizar$#', $path, $matches) === 1) {
            $this->controller('emissionArtifacts')->preview(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/imprimir$#', $path, $matches) === 1) {
            $this->controller('emissionArtifacts')->startPrint(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/impressao/(\d+)/resultado$#', $path, $matches) === 1) {
            $this->controller('emissionArtifacts')->finishPrint(strtolower($matches[1]), (int) $matches[2]);
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/impressao/(\d+)$#', $path, $matches) === 1) {
            $this->controller('emissionArtifacts')->printView(strtolower($matches[1]), (int) $matches[2]);
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/termo-entrega$#', $path, $matches) === 1) {
            $this->controller('emissions')->printReceiptTerm(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/termo-assinado/anexar$#', $path, $matches) === 1) {
            $this->controller('emissions')->attachSignedTerm(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/termo-assinado/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
            $this->controller('emissions')->signedTermFile(strtolower($matches[1]), strtolower($matches[2]));
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/entregar$#', $path, $matches) === 1) {
            $this->controller('emissions')->deliver(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})/cancelar$#', $path, $matches) === 1) {
            $this->controller('emissions')->cancel(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/emissoes/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
            $this->controller('emissions')->show(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/processos/([0-9a-fA-F-]{36})/gerar-emissao$#', $path, $matches) === 1) {
            $this->controller('emissions')->generate(strtolower($matches[1]));
        }

        if ($path === '/cif/processos/novo') {
            $this->controller('processes')->create();
        }

        if (preg_match('#^/cif/processos/arquivo/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
            $this->controller('processes')->file(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/processos/([0-9a-fA-F-]{36})/editar$#', $path, $matches) === 1) {
            $this->controller('processes')->edit(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/processos/([0-9a-fA-F-]{36})/transicao$#', $path, $matches) === 1) {
            $this->controller('processes')->transition(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/processos/([0-9a-fA-F-]{36})/validar-dados$#', $path, $matches) === 1) {
            $this->controller('processes')->validateBiography(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/processos/([0-9a-fA-F-]{36})/documentos$#', $path, $matches) === 1) {
            $this->controller('processes')->addDocument(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/processos/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
            $this->controller('processes')->show(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/agentes/importacoes/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
            $this->controller('agentImports')->batch(strtolower($matches[1]));
        }


        if (preg_match('#^/cif/agentes/([0-9a-fA-F-]{36})/biometria/([0-9a-fA-F-]{36})/invalidar$#', $path, $matches) === 1) {
            $this->controller('biometrics')->invalidate(strtolower($matches[1]), strtolower($matches[2]));
        }

        if (preg_match('#^/cif/agentes/([0-9a-fA-F-]{36})/biometria$#', $path, $matches) === 1) {
            $this->controller('biometrics')->manage(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/agentes/([0-9a-fA-F-]{36})/status$#', $path, $matches) === 1) {
            $this->controller('agents')->status(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/agentes/([0-9a-fA-F-]{36})/excluir$#', $path, $matches) === 1) {
            $this->controller('agents')->delete(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/biometria/arquivo/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
            $this->controller('biometrics')->file(strtolower($matches[1]));
        }

        if ($path === '/cif/agentes/novo') {
            $this->controller('agents')->create();
        }

        if (preg_match('#^/cif/agentes/([0-9a-fA-F-]{36})/editar$#', $path, $matches) === 1) {
            $this->controller('agents')->edit(strtolower($matches[1]));
        }

        if (preg_match('#^/cif/agentes/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
            $this->controller('agents')->show(strtolower($matches[1]));
        }

        // Qualquer rota /cif desconhecida recebe 404 próprio do sistema.
        $view = new View(SMTT_CIF_GESTAO_PATH . 'templates');
        $view->render('error/not-found', [
            'title' => 'Página não encontrada',
            'message' => 'A área solicitada não existe no Sistema CIF.',
            'currentUser' => null,
        ], 404);
    }

    private function relativePath(): ?string
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $requestPath = wp_parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($requestPath)) {
            return null;
        }

        $homePath = wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (is_string($homePath) && $homePath !== '/' && $homePath !== '') {
            $homePath = '/' . trim($homePath, '/');
            if ($requestPath === $homePath) {
                return '/';
            }
            if (str_starts_with($requestPath, $homePath . '/')) {
                $requestPath = substr($requestPath, strlen($homePath));
            }
        }

        $normalized = '/' . ltrim($requestPath, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }
}
