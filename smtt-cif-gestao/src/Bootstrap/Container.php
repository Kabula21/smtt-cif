<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Bootstrap;

use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Command\GrantAdminCommand;
use SMTT\CIF\Gestao\Database\Connection;
use SMTT\CIF\Gestao\Database\DatabaseProvisioner;
use SMTT\CIF\Gestao\Database\MigrationRunner;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Repository\AuthorizationRepository;
use SMTT\CIF\Gestao\Repository\SessionRepository;
use SMTT\CIF\Gestao\Repository\AuditRepository;
use SMTT\CIF\Gestao\Repository\ReportRepository;
use SMTT\CIF\Gestao\Repository\AgentRepository;
use SMTT\CIF\Gestao\Repository\AgentImportRepository;
use SMTT\CIF\Gestao\Repository\BiometricRepository;
use SMTT\CIF\Gestao\Repository\IssuanceProcessRepository;
use SMTT\CIF\Gestao\Repository\EmissionRepository;
use SMTT\CIF\Gestao\Repository\ArtifactRepository;
use SMTT\CIF\Gestao\Repository\OccurrenceRepository;
use SMTT\CIF\Gestao\Repository\SyncRepository;
use SMTT\CIF\Gestao\Import\AgentFileParser;
use SMTT\CIF\Gestao\Import\AgentTemplateBuilder;
use SMTT\CIF\Gestao\Biometry\ImageInspector;
use SMTT\CIF\Gestao\Biometry\ImageNormalizer;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\DataProtection;
use SMTT\CIF\Gestao\Security\SecretKeyStore;
use SMTT\CIF\Gestao\Security\CurrentUser;
use SMTT\CIF\Gestao\Security\SessionManager;
use SMTT\CIF\Gestao\Security\CsrfToken;
use SMTT\CIF\Gestao\Security\PasswordHasher;
use SMTT\CIF\Gestao\Storage\PrivateStorage;
use SMTT\CIF\Gestao\Service\UserAccessService;
use SMTT\CIF\Gestao\Service\AuthenticationService;
use SMTT\CIF\Gestao\Service\AuditIntegrityService;
use SMTT\CIF\Gestao\Service\AgentService;
use SMTT\CIF\Gestao\Service\AgentImportService;
use SMTT\CIF\Gestao\Service\AgentExportService;
use SMTT\CIF\Gestao\Service\BiometricService;
use SMTT\CIF\Gestao\Service\IssuanceProcessService;
use SMTT\CIF\Gestao\Service\EmissionService;
use SMTT\CIF\Gestao\Service\EmissionSetupService;
use SMTT\CIF\Gestao\Service\CardArtifactService;
use SMTT\CIF\Gestao\Service\CardTemplateRenderer;
use SMTT\CIF\Gestao\Service\DeliveryService;
use SMTT\CIF\Gestao\Service\SignedDeliveryTermService;
use SMTT\CIF\Gestao\Service\OccurrenceService;
use SMTT\CIF\Gestao\Service\SyncConfigService;
use SMTT\CIF\Gestao\Sync\SyncSigningKeyStore;
use SMTT\CIF\Gestao\Sync\MessageSigner;
use SMTT\CIF\Gestao\Sync\SignedHttpClient;
use SMTT\CIF\Gestao\Sync\ProjectionPayloadBuilder;
use SMTT\CIF\Gestao\Sync\RetryPolicy;
use SMTT\CIF\Gestao\Sync\HeartbeatService;
use SMTT\CIF\Gestao\Sync\SyncWorker;
use SMTT\CIF\Gestao\Sync\ReconciliationService;
use SMTT\CIF\Gestao\Sync\SyncScheduler;
use SMTT\CIF\Gestao\Web\Controller\DashboardController;
use SMTT\CIF\Gestao\Web\Controller\AgentController;
use SMTT\CIF\Gestao\Web\Controller\AgentImportController;
use SMTT\CIF\Gestao\Web\Controller\BiometricController;
use SMTT\CIF\Gestao\Web\Controller\IssuanceProcessController;
use SMTT\CIF\Gestao\Web\Controller\EmissionController;
use SMTT\CIF\Gestao\Web\Controller\EmissionArtifactController;
use SMTT\CIF\Gestao\Web\Controller\OccurrenceController;
use SMTT\CIF\Gestao\Web\Controller\LoginController;
use SMTT\CIF\Gestao\Web\Controller\PasswordController;
use SMTT\CIF\Gestao\Web\Controller\UserAccessController;
use SMTT\CIF\Gestao\Web\Controller\SyncController;
use SMTT\CIF\Gestao\Web\Controller\ReportController;
use SMTT\CIF\Gestao\Web\Controller\AuditController;
use SMTT\CIF\Gestao\Web\Controller\DiagnosticController;
use SMTT\CIF\Gestao\Web\Router;
use SMTT\CIF\Gestao\Web\SecurityHeaders;
use SMTT\CIF\Gestao\Web\View;

final class Container
{
    private Environment $environment;
    private ?Connection $connection = null;
    private ?DatabaseProvisioner $databaseProvisioner = null;
    private ?TransactionManager $transactions = null;
    private ?MigrationRunner $migrations = null;
    private ?Installer $installer = null;
    private ?AuthorizationRepository $authorizationRepository = null;
    private ?SessionRepository $sessionRepository = null;
    private ?AuditRepository $auditRepository = null;
    private ?ReportRepository $reportRepository = null;
    private ?AgentRepository $agentRepository = null;
    private ?BiometricRepository $biometricRepository = null;
    private ?IssuanceProcessRepository $issuanceProcessRepository = null;
    private ?EmissionRepository $emissionRepository = null;
    private ?ArtifactRepository $artifactRepository = null;
    private ?OccurrenceRepository $occurrenceRepository = null;
    private ?SyncRepository $syncRepository = null;
    private ?SyncSigningKeyStore $syncSigningKeyStore = null;
    private ?MessageSigner $messageSigner = null;
    private ?SignedHttpClient $signedHttpClient = null;
    private ?ProjectionPayloadBuilder $projectionPayloadBuilder = null;
    private ?RetryPolicy $retryPolicy = null;
    private ?HeartbeatService $heartbeatService = null;
    private ?SyncWorker $syncWorker = null;
    private ?ReconciliationService $reconciliationService = null;
    private ?SyncScheduler $syncScheduler = null;
    private ?SyncConfigService $syncConfigService = null;
    private ?AgentImportRepository $agentImportRepository = null;
    private ?AgentFileParser $agentFileParser = null;
    private ?AgentTemplateBuilder $agentTemplateBuilder = null;
    private ?AgentImportService $agentImportService = null;
    private ?AgentExportService $agentExportService = null;
    private ?ImageInspector $imageInspector = null;
    private ?ImageNormalizer $imageNormalizer = null;
    private ?PrivateStorage $privateStorage = null;
    private ?BiometricService $biometricService = null;
    private ?IssuanceProcessService $issuanceProcessService = null;
    private ?EmissionService $emissionService = null;
    private ?EmissionSetupService $emissionSetupService = null;
    private ?CardArtifactService $cardArtifactService = null;
    private ?CardTemplateRenderer $cardTemplateRenderer = null;
    private ?DeliveryService $deliveryService = null;
    private ?SignedDeliveryTermService $signedDeliveryTermService = null;
    private ?OccurrenceService $occurrenceService = null;
    private ?SecretKeyStore $secretKeyStore = null;
    private ?DataProtection $dataProtection = null;
    private ?AgentService $agentService = null;
    private ?AuditLogger $auditLogger = null;
    private ?AuditIntegrityService $auditIntegrityService = null;
    private ?UserAccessService $userAccessService = null;
    private ?AuthenticationService $authenticationService = null;
    private ?PasswordHasher $passwordHasher = null;
    private ?SessionManager $sessionManager = null;
    private ?CsrfToken $csrfToken = null;
    private ?CurrentUser $currentUser = null;
    private ?AccessGate $accessGate = null;
    private ?View $view = null;
    private ?Router $router = null;
    private ?FirstAdminBootstrap $firstAdminBootstrap = null;

    public function __construct()
    {
        $this->environment = new Environment();
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function connection(): Connection
    {
        return $this->connection ??= new Connection($this->environment);
    }

    public function databaseProvisioner(): DatabaseProvisioner
    {
        return $this->databaseProvisioner ??= new DatabaseProvisioner(
            $this->environment,
            $this->connection()
        );
    }

    public function transactions(): TransactionManager
    {
        return $this->transactions ??= new TransactionManager($this->connection()->mysqli());
    }

    public function migrations(): MigrationRunner
    {
        return $this->migrations ??= new MigrationRunner(
            $this->connection()->mysqli(),
            SMTT_CIF_GESTAO_PATH . 'migrations'
        );
    }

    public function installer(): Installer
    {
        return $this->installer ??= new Installer(
            $this->databaseProvisioner(),
            $this->connection(),
            SMTT_CIF_GESTAO_PATH . 'migrations'
        );
    }

    public function authorizationRepository(): AuthorizationRepository
    {
        return $this->authorizationRepository ??= new AuthorizationRepository($this->connection()->mysqli());
    }

    public function sessionRepository(): SessionRepository
    {
        return $this->sessionRepository ??= new SessionRepository($this->connection()->mysqli());
    }

    public function auditRepository(): AuditRepository
    {
        return $this->auditRepository ??= new AuditRepository($this->connection()->mysqli());
    }

    public function reportRepository(): ReportRepository
    {
        return $this->reportRepository ??= new ReportRepository($this->connection()->mysqli());
    }

    public function agentRepository(): AgentRepository
    {
        return $this->agentRepository ??= new AgentRepository($this->connection()->mysqli());
    }

    public function biometricRepository(): BiometricRepository
    {
        return $this->biometricRepository ??= new BiometricRepository($this->connection()->mysqli());
    }

    public function issuanceProcessRepository(): IssuanceProcessRepository
    {
        return $this->issuanceProcessRepository ??= new IssuanceProcessRepository($this->connection()->mysqli());
    }

    public function emissionRepository(): EmissionRepository
    {
        return $this->emissionRepository ??= new EmissionRepository($this->connection()->mysqli());
    }

    public function artifactRepository(): ArtifactRepository
    {
        return $this->artifactRepository ??= new ArtifactRepository($this->connection()->mysqli());
    }

    public function occurrenceRepository(): OccurrenceRepository
    {
        return $this->occurrenceRepository ??= new OccurrenceRepository($this->connection()->mysqli());
    }

    public function syncRepository(): SyncRepository
    {
        return $this->syncRepository ??= new SyncRepository($this->connection()->mysqli());
    }

    public function agentImportRepository(): AgentImportRepository
    {
        return $this->agentImportRepository ??= new AgentImportRepository($this->connection()->mysqli());
    }

    public function agentFileParser(): AgentFileParser
    {
        return $this->agentFileParser ??= new AgentFileParser();
    }

    public function agentTemplateBuilder(): AgentTemplateBuilder
    {
        return $this->agentTemplateBuilder ??= new AgentTemplateBuilder();
    }

    public function secretKeyStore(): SecretKeyStore
    {
        return $this->secretKeyStore ??= new SecretKeyStore($this->environment());
    }

    public function dataProtection(): DataProtection
    {
        return $this->dataProtection ??= new DataProtection($this->secretKeyStore());
    }

    public function agentService(): AgentService
    {
        return $this->agentService ??= new AgentService(
            $this->agentRepository(),
            $this->transactions(),
            $this->auditLogger(),
            $this->dataProtection(),
            $this->privateStorage()
        );
    }

    public function agentImportService(): AgentImportService
    {
        return $this->agentImportService ??= new AgentImportService(
            $this->agentFileParser(),
            $this->agentImportRepository(),
            $this->agentRepository(),
            $this->agentService(),
            $this->dataProtection(),
            $this->transactions(),
            $this->auditLogger()
        );
    }

    public function agentExportService(): AgentExportService
    {
        return $this->agentExportService ??= new AgentExportService(
            $this->agentRepository(),
            $this->auditLogger()
        );
    }

    public function imageInspector(): ImageInspector
    {
        return $this->imageInspector ??= new ImageInspector();
    }

    public function imageNormalizer(): ImageNormalizer
    {
        return $this->imageNormalizer ??= new ImageNormalizer();
    }

    public function privateStorage(): PrivateStorage
    {
        return $this->privateStorage ??= new PrivateStorage(
            $this->environment(),
            $this->secretKeyStore()
        );
    }

    public function biometricService(): BiometricService
    {
        return $this->biometricService ??= new BiometricService(
            $this->biometricRepository(),
            $this->transactions(),
            $this->auditLogger(),
            $this->imageInspector(),
            $this->imageNormalizer(),
            $this->privateStorage()
        );
    }

    public function issuanceProcessService(): IssuanceProcessService
    {
        return $this->issuanceProcessService ??= new IssuanceProcessService(
            $this->issuanceProcessRepository(),
            $this->transactions(),
            $this->auditLogger(),
            $this->privateStorage(),
            $this->dataProtection()
        );
    }

    public function emissionSetupService(): EmissionSetupService
    {
        return $this->emissionSetupService ??= new EmissionSetupService(
            $this->emissionRepository(),
            $this->transactions(),
            $this->auditLogger(),
            $this->privateStorage(),
            $this->imageInspector(),
            $this->imageNormalizer()
        );
    }

    public function emissionService(): EmissionService
    {
        return $this->emissionService ??= new EmissionService(
            $this->emissionRepository(),
            $this->issuanceProcessRepository(),
            $this->transactions(),
            $this->auditLogger(),
            $this->dataProtection(),
            $this->emissionSetupService()
        );
    }

    public function cardTemplateRenderer(): CardTemplateRenderer
    {
        return $this->cardTemplateRenderer ??= new CardTemplateRenderer();
    }

    public function deliveryService(): DeliveryService
    {
        return $this->deliveryService ??= new DeliveryService(
            $this->emissionRepository(),
            $this->transactions(),
            $this->auditLogger(),
            $this->privateStorage()
        );
    }

    public function signedDeliveryTermService(): SignedDeliveryTermService
    {
        return $this->signedDeliveryTermService ??= new SignedDeliveryTermService(
            $this->emissionRepository(), $this->transactions(), $this->auditLogger(), $this->privateStorage()
        );
    }

    public function occurrenceService(): OccurrenceService
    {
        return $this->occurrenceService ??= new OccurrenceService(
            $this->occurrenceRepository(),
            $this->emissionRepository(),
            $this->transactions(),
            $this->auditLogger(),
            $this->privateStorage()
        );
    }

    public function cardArtifactService(): CardArtifactService
    {
        return $this->cardArtifactService ??= new CardArtifactService(
            $this->artifactRepository(),
            $this->transactions(),
            $this->auditLogger(),
            $this->privateStorage(),
            $this->dataProtection(),
            $this->cardTemplateRenderer(),
            SMTT_CIF_GESTAO_PATH . 'assets/js/vendor/qr-bundle.js'
        );
    }

    public function syncSigningKeyStore(): SyncSigningKeyStore
    {
        return $this->syncSigningKeyStore ??= new SyncSigningKeyStore($this->environment());
    }

    public function messageSigner(): MessageSigner
    {
        return $this->messageSigner ??= new MessageSigner($this->syncSigningKeyStore());
    }

    public function signedHttpClient(): SignedHttpClient
    {
        return $this->signedHttpClient ??= new SignedHttpClient($this->messageSigner());
    }

    public function projectionPayloadBuilder(): ProjectionPayloadBuilder
    {
        return $this->projectionPayloadBuilder ??= new ProjectionPayloadBuilder(
            $this->emissionRepository(), $this->syncRepository(), $this->dataProtection()
        );
    }

    public function retryPolicy(): RetryPolicy
    {
        return $this->retryPolicy ??= new RetryPolicy();
    }

    public function syncConfigService(): SyncConfigService
    {
        return $this->syncConfigService ??= new SyncConfigService(
            $this->syncRepository(), $this->syncSigningKeyStore(), $this->transactions(), $this->auditLogger()
        );
    }

    public function heartbeatService(): HeartbeatService
    {
        return $this->heartbeatService ??= new HeartbeatService(
            $this->syncRepository(), $this->syncConfigService(), $this->signedHttpClient()
        );
    }

    public function syncWorker(): SyncWorker
    {
        return $this->syncWorker ??= new SyncWorker(
            $this->syncRepository(), $this->syncConfigService(), $this->projectionPayloadBuilder(),
            $this->signedHttpClient(), $this->retryPolicy(), $this->heartbeatService()
        );
    }

    public function reconciliationService(): ReconciliationService
    {
        return $this->reconciliationService ??= new ReconciliationService(
            $this->syncRepository(), $this->emissionRepository(), $this->syncConfigService(), $this->signedHttpClient()
        );
    }

    public function syncScheduler(): SyncScheduler
    {
        return $this->syncScheduler ??= new SyncScheduler(
            fn(): SyncRepository => $this->syncRepository(),
            fn(): SyncConfigService => $this->syncConfigService(),
            fn(): SyncWorker => $this->syncWorker(),
            fn(): ReconciliationService => $this->reconciliationService()
        );
    }

    public function auditLogger(): AuditLogger
    {
        return $this->auditLogger ??= new AuditLogger($this->connection()->mysqli(), $this->transactions());
    }

    public function auditIntegrityService(): AuditIntegrityService
    {
        return $this->auditIntegrityService ??= new AuditIntegrityService($this->auditRepository());
    }

    public function passwordHasher(): PasswordHasher
    {
        return $this->passwordHasher ??= new PasswordHasher();
    }

    public function sessionManager(): SessionManager
    {
        return $this->sessionManager ??= new SessionManager($this->sessionRepository());
    }

    public function csrfToken(): CsrfToken
    {
        return $this->csrfToken ??= new CsrfToken();
    }

    public function authenticationService(): AuthenticationService
    {
        return $this->authenticationService ??= new AuthenticationService(
            $this->authorizationRepository(),
            $this->sessionManager(),
            $this->passwordHasher(),
            $this->auditLogger()
        );
    }

    public function userAccessService(): UserAccessService
    {
        return $this->userAccessService ??= new UserAccessService(
            $this->authorizationRepository(),
            $this->transactions(),
            $this->auditLogger(),
            $this->passwordHasher(),
            $this->sessionManager()
        );
    }

    public function currentUser(): CurrentUser
    {
        return $this->currentUser ??= new CurrentUser($this->authorizationRepository(), $this->sessionManager());
    }

    public function accessGate(): AccessGate
    {
        return $this->accessGate ??= new AccessGate($this->currentUser());
    }

    public function view(): View
    {
        return $this->view ??= new View(SMTT_CIF_GESTAO_PATH . 'templates', $this->csrfToken());
    }

    public function router(): Router
    {
        if ($this->router !== null) {
            return $this->router;
        }

        // Controllers são materializados somente quando a rota correspondente é usada.
        // Isso evita construir toda a árvore de serviços (biometria, artefatos, sync,
        // relatórios etc.) em cada abertura de página do Sistema CIF.
        $factories = [
            'login' => fn(): LoginController => new LoginController(
                $this->view(), $this->currentUser(), $this->authenticationService(), $this->csrfToken()
            ),
            'password' => fn(): PasswordController => new PasswordController(
                $this->view(), $this->accessGate(), $this->authenticationService(), $this->currentUser()
            ),
            'dashboard' => fn(): DashboardController => new DashboardController(
                $this->view(), $this->accessGate(), $this->authorizationRepository(), $this->migrations(),
                $this->agentRepository(), $this->issuanceProcessRepository(), $this->emissionRepository()
            ),
            'userAccess' => fn(): UserAccessController => new UserAccessController(
                $this->view(), $this->accessGate(), $this->authorizationRepository(), $this->userAccessService()
            ),
            'agents' => fn(): AgentController => new AgentController(
                $this->view(), $this->accessGate(), $this->agentRepository(), $this->agentService(),
                $this->agentExportService(), $this->biometricService()
            ),
            'agentImports' => fn(): AgentImportController => new AgentImportController(
                $this->view(), $this->accessGate(), $this->agentImportService(), $this->agentTemplateBuilder()
            ),
            'biometrics' => fn(): BiometricController => new BiometricController(
                $this->view(), $this->accessGate(), $this->biometricService()
            ),
            'processes' => fn(): IssuanceProcessController => new IssuanceProcessController(
                $this->view(), $this->accessGate(), $this->issuanceProcessRepository(),
                $this->agentRepository(), $this->issuanceProcessService()
            ),
            'emissions' => fn(): EmissionController => new EmissionController(
                $this->view(), $this->accessGate(), $this->emissionRepository(), $this->emissionService(),
                $this->emissionSetupService(), $this->cardArtifactService(), $this->deliveryService(),
                $this->signedDeliveryTermService()
            ),
            'emissionArtifacts' => fn(): EmissionArtifactController => new EmissionArtifactController(
                $this->view(), $this->accessGate(), $this->cardArtifactService()
            ),
            'occurrences' => fn(): OccurrenceController => new OccurrenceController(
                $this->view(), $this->accessGate(), $this->emissionRepository(), $this->occurrenceService()
            ),
            'reports' => fn(): ReportController => new ReportController(
                $this->view(), $this->accessGate(), $this->reportRepository(), $this->auditLogger()
            ),
            'audit' => fn(): AuditController => new AuditController(
                $this->view(), $this->accessGate(), $this->auditRepository(), $this->auditIntegrityService()
            ),
            'diagnostics' => fn(): DiagnosticController => new DiagnosticController(
                $this->view(), $this->accessGate(), $this->environment(), $this->migrations(),
                $this->emissionSetupService(), $this->syncConfigService(), $this->auditIntegrityService()
            ),
            'sync' => fn(): SyncController => new SyncController(
                $this->view(), $this->accessGate(), $this->syncRepository(), $this->syncConfigService(),
                $this->syncWorker(), $this->heartbeatService(), $this->reconciliationService(), $this->syncScheduler()
            ),
        ];

        return $this->router = new Router(new SecurityHeaders(), fn(): CurrentUser => $this->currentUser(), $factories);
    }

    public function firstAdminBootstrap(): FirstAdminBootstrap
    {
        return $this->firstAdminBootstrap ??= new FirstAdminBootstrap(
            $this->authorizationRepository(),
            $this->userAccessService()
        );
    }

    public function grantAdminCommand(): GrantAdminCommand
    {
        return new GrantAdminCommand(
            $this->authorizationRepository(),
            $this->userAccessService()
        );
    }
}
