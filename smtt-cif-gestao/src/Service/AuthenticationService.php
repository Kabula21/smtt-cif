<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Repository\AuthorizationRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Security\PasswordHasher;
use SMTT\CIF\Gestao\Security\SessionManager;

final class AuthenticationService
{
    public function __construct(
        private readonly AuthorizationRepository $repository,
        private readonly SessionManager $sessions,
        private readonly PasswordHasher $passwords,
        private readonly AuditLogger $audit
    ) {
    }

    public function login(string $login, string $password, bool $remember): CifUser
    {
        $login = strtolower(trim($login));
        if ($login === '' || $password === '') {
            throw new RuntimeException('Informe usuário e senha.');
        }

        $record = $this->repository->authenticationRecord($login);
        if ($record === null) {
            $this->consumeDummyHash($password);
            throw new RuntimeException('Usuário ou senha inválidos.');
        }

        $user = $record['user'];
        $hash = $record['password_hash'];
        $lockedUntil = $record['locked_until'];
        if ($lockedUntil !== null) {
            $lockedTs = strtotime($lockedUntil . ' UTC');
            if ($lockedTs !== false && $lockedTs > time()) {
                throw new RuntimeException('Acesso temporariamente bloqueado após tentativas inválidas. Tente novamente em alguns minutos.');
            }
        }

        if (!$user->active || $hash === null || !$this->passwords->verify($password, $hash)) {
            $this->repository->recordLoginFailure($user->id);
            throw new RuntimeException('Usuário ou senha inválidos.');
        }

        if ($this->passwords->needsRehash($hash)) {
            $this->repository->setPasswordHash($user->id, $this->passwords->hash($password), $user->mustChangePassword);
        }

        $this->repository->recordSuccessfulLogin($user->id);
        $fresh = $this->repository->findById($user->id) ?? $user;
        $this->sessions->start($fresh->id, $remember);
        $this->audit->log('AUTH_LOGIN_CIF', 'usuario_cif', $fresh->id, $fresh->uuid, $fresh, 'WEB', null, [
            'login' => $fresh->login,
            'setor' => $fresh->department,
            'perfis' => $fresh->profiles,
        ]);
        return $fresh;
    }

    public function logout(?CifUser $user): void
    {
        if ($user !== null) {
            $this->audit->log('AUTH_LOGOUT_CIF', 'usuario_cif', $user->id, $user->uuid, $user, 'WEB');
        }
        $this->sessions->logout();
    }

    public function changeOwnPassword(CifUser $user, string $currentPassword, string $newPassword): void
    {
        $hash = $this->repository->passwordHash($user->id);
        if ($hash === null || !$this->passwords->verify($currentPassword, $hash)) {
            throw new RuntimeException('A senha atual não confere.');
        }
        if (hash_equals($currentPassword, $newPassword)) {
            throw new RuntimeException('A nova senha deve ser diferente da senha atual.');
        }
        $newHash = $this->passwords->hash($newPassword);
        $this->repository->setPasswordHash($user->id, $newHash, false);
        $this->audit->log('SEGURANCA_SENHA_PROPRIA_ALTERADA', 'usuario_cif', $user->id, $user->uuid, $user, 'WEB');
        $this->sessions->revokeUser($user->id);
    }

    private function consumeDummyHash(string $password): void
    {
        static $dummy = null;
        if (!is_string($dummy)) {
            $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $dummy = password_hash('SMTT-CIF-dummy-password-not-valid', $algorithm);
        }
        if (is_string($dummy)) {
            password_verify($password, $dummy);
        }
    }
}
