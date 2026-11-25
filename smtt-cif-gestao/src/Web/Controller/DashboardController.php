<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use SMTT\CIF\Gestao\Database\MigrationRunner;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Repository\AuthorizationRepository;
use SMTT\CIF\Gestao\Repository\AgentRepository;
use SMTT\CIF\Gestao\Repository\IssuanceProcessRepository;
use SMTT\CIF\Gestao\Repository\EmissionRepository;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Web\View;

final class DashboardController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly AuthorizationRepository $authorization,
        private readonly MigrationRunner $migrations,
        private readonly AgentRepository $agents,
        private readonly IssuanceProcessRepository $processes,
        private readonly EmissionRepository $emissions
    ) {
    }

    public function handle(): never
    {
        $user = $this->gate->user();
        if ($user === null) {
            wp_safe_redirect(home_url('/cif/login'));
            exit;
        }

        $this->view->render('dashboard/index', [
            'title' => 'Painel do Sistema CIF',
            'currentUser' => $user,
            'activeNav' => 'dashboard',
        ]);
    }
}
