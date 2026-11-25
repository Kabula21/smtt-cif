<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Security;

use SMTT\CIF\Gestao\Repository\AuthorizationRepository;

final class CurrentUser
{
    private bool $resolved = false;
    private ?CifUser $user = null;

    public function __construct(
        private readonly AuthorizationRepository $repository,
        private readonly SessionManager $sessions
    ) {
    }

    public function get(): ?CifUser
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $userId = $this->sessions->userId();
        if ($userId === null) {
            return $this->user = null;
        }

        $user = $this->repository->findById($userId);
        if ($user === null || !$user->active) {
            $this->sessions->logout();
            return $this->user = null;
        }

        return $this->user = $user;
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->user = null;
    }
}
