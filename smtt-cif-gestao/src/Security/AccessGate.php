<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Security;

final class AccessGate
{
    public function __construct(private readonly CurrentUser $currentUser)
    {
    }

    public function user(): ?CifUser
    {
        return $this->currentUser->get();
    }

    public function allows(string $permission): bool
    {
        $user = $this->user();
        return $user !== null && $user->can($permission);
    }

    public function requireUser(): CifUser
    {
        $user = $this->user();
        if ($user === null) {
            throw new AuthorizationException('É necessário autenticar-se no Sistema CIF.');
        }
        return $user;
    }

    public function requirePermission(string $permission): CifUser
    {
        $user = $this->requireUser();
        if (!$user->can($permission)) {
            throw new AuthorizationException('Seu usuário não possui permissão para executar esta operação.');
        }
        return $user;
    }

    public function requireSystemAdministrator(): CifUser
    {
        $user = $this->requireUser();
        if (!$user->isSystemAdministrator()) {
            throw new AuthorizationException('Esta área é restrita ao Administrador CIF.');
        }
        return $user;
    }
}
