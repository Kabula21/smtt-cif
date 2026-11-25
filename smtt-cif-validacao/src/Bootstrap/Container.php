<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Bootstrap;

use SMTT\CIF\Validacao\Database\Connection;
use SMTT\CIF\Validacao\Database\DatabaseProvisioner;
use SMTT\CIF\Validacao\Database\MigrationRunner;
use SMTT\CIF\Validacao\Database\TransactionManager;
use SMTT\CIF\Validacao\Repository\PublicIssuanceRepository;
use SMTT\CIF\Validacao\Repository\RateLimitRepository;
use SMTT\CIF\Validacao\Repository\SettingsRepository;
use SMTT\CIF\Validacao\Repository\SyncRepository;
use SMTT\CIF\Validacao\Security\RequestAuthenticator;
use SMTT\CIF\Validacao\Service\RuntimeModeService;
use SMTT\CIF\Validacao\Service\SyncService;
use SMTT\CIF\Validacao\Service\ValidationService;
use SMTT\CIF\Validacao\Sync\PayloadValidator;
use SMTT\CIF\Validacao\Web\AdminController;
use SMTT\CIF\Validacao\Web\PublicValidationController;
use SMTT\CIF\Validacao\Web\RestController;

final class Container
{
    private Environment $environment;
    private ?Connection $connection = null;
    private ?DatabaseProvisioner $provisioner = null;
    private ?MigrationRunner $migrations = null;
    private ?TransactionManager $transactions = null;
    private ?SettingsRepository $settings = null;
    private ?SyncRepository $syncRepository = null;
    private ?PublicIssuanceRepository $issuances = null;
    private ?RateLimitRepository $rateLimits = null;
    private ?RuntimeModeService $runtimeMode = null;
    private ?RequestAuthenticator $authenticator = null;
    private ?PayloadValidator $validator = null;
    private ?SyncService $syncService = null;
    private ?ValidationService $validationService = null;
    private ?RestController $rest = null;
    private ?PublicValidationController $public = null;
    private ?AdminController $admin = null;
    private ?Installer $installer = null;

    public function __construct()
    {
        $this->environment = new Environment();
    }

    public function environment(): Environment { return $this->environment; }
    public function connection(): Connection { return $this->connection ??= new Connection($this->environment); }
    public function provisioner(): DatabaseProvisioner { return $this->provisioner ??= new DatabaseProvisioner($this->environment, $this->connection()); }
    public function migrations(): MigrationRunner { return $this->migrations ??= new MigrationRunner($this->connection()->mysqli(), SMTT_CIF_VALIDACAO_PATH . 'migrations'); }
    public function transactions(): TransactionManager { return $this->transactions ??= new TransactionManager($this->connection()->mysqli()); }
    public function settings(): SettingsRepository { return $this->settings ??= new SettingsRepository($this->connection()->mysqli()); }
    public function syncRepository(): SyncRepository { return $this->syncRepository ??= new SyncRepository($this->connection()->mysqli()); }
    public function issuances(): PublicIssuanceRepository { return $this->issuances ??= new PublicIssuanceRepository($this->connection()->mysqli()); }
    public function rateLimits(): RateLimitRepository { return $this->rateLimits ??= new RateLimitRepository($this->connection()->mysqli()); }
    public function runtimeMode(): RuntimeModeService { return $this->runtimeMode ??= new RuntimeModeService($this->settings()); }
    public function authenticator(): RequestAuthenticator { return $this->authenticator ??= new RequestAuthenticator($this->syncRepository(), $this->settings(), $this->runtimeMode()); }
    public function validator(): PayloadValidator { return $this->validator ??= new PayloadValidator(); }
    public function syncService(): SyncService { return $this->syncService ??= new SyncService($this->issuances(), $this->syncRepository(), $this->transactions()); }
    public function validationService(): ValidationService { return $this->validationService ??= new ValidationService($this->issuances(), $this->rateLimits(), $this->transactions()); }
    public function rest(): RestController { return $this->rest ??= new RestController($this->authenticator(), $this->validator(), $this->syncService(), $this->syncRepository(), $this->runtimeMode(), fn(): string => $this->migrations()->latestVersion()); }
    public function publicController(): PublicValidationController { return $this->public ??= new PublicValidationController($this->validationService(), $this->runtimeMode()); }
    public function admin(): AdminController { return $this->admin ??= new AdminController($this->environment(), $this->settings(), $this->syncRepository(), $this->issuances(), $this->migrations(), $this->runtimeMode()); }
    public function installer(): Installer { return $this->installer ??= new Installer($this->provisioner(), $this->connection(), SMTT_CIF_VALIDACAO_PATH . 'migrations'); }
}
