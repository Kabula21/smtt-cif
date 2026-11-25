<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Command;

use SMTT\CIF\Gestao\Repository\AuthorizationRepository;
use SMTT\CIF\Gestao\Service\UserAccessService;

final class GrantAdminCommand
{
    public function __construct(
        private readonly AuthorizationRepository $authorization,
        private readonly UserAccessService $userAccess
    ) {
    }

    /**
     * Configura o primeiro Administrador CIF com credenciais próprias.
     *
     * ## OPTIONS
     * <login> : Login próprio do Sistema CIF.
     * --email=<email> : E-mail do administrador.
     * --name=<nome> : Nome completo.
     * --sector=<setor> : Setor/unidade.
     * --password=<senha> : Senha inicial com no mínimo 10 caracteres.
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if ($this->authorization->countConfiguredAdmins() > 0) {
            \WP_CLI::error('O Administrador CIF inicial já possui credenciais próprias.');
        }
        $login = trim((string)($args[0] ?? ''));
        $email = trim((string)($assocArgs['email'] ?? ''));
        $name = trim((string)($assocArgs['name'] ?? ''));
        $sector = trim((string)($assocArgs['sector'] ?? ''));
        $password = (string)($assocArgs['password'] ?? '');
        if ($login==='' || $email==='' || $name==='' || $sector==='' || $password==='') {
            \WP_CLI::error('Informe login, --email, --name, --sector e --password.');
        }
        $user = $this->userAccess->bootstrapAdmin($login,$email,$name,$sector,$password,'BOOTSTRAP_CLI');
        \WP_CLI::success(sprintf('Administrador CIF configurado: %s (%s).',$user->name,$user->login));
    }
}
