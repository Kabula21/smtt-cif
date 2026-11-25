<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Repository\AuthorizationRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Security\PasswordHasher;
use SMTT\CIF\Gestao\Security\SessionManager;

final class UserAccessService
{
    public function __construct(
        private readonly AuthorizationRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
        private readonly PasswordHasher $passwords,
        private readonly SessionManager $sessions
    ) {
    }

    public function bootstrapAdmin(
        string $login,
        string $email,
        string $name,
        string $department,
        string $password,
        string $origin='BOOTSTRAP'
    ): CifUser {
        [$login,$email,$name,$department] = $this->normalizeIdentity($login,$email,$name,$department);
        $passwordHash = $this->passwords->hash($password);

        return $this->transactions->run(function () use ($login,$email,$name,$department,$passwordHash,$origin): CifUser {
            $this->repository->lockProfileByCode('ADMIN_CIF');
            if ($this->repository->countConfiguredAdmins() > 0) {
                throw new RuntimeException('O Administrador CIF inicial já possui credenciais próprias.');
            }

            $target = $this->repository->firstUnconfiguredAdmin();
            $this->assertUniqueIdentity($login,$email,$target?->id);
            if ($target !== null) {
                $this->repository->configureNativeIdentity($target->id,$login,$email,$name,$department,$passwordHash,false);
                $this->repository->replaceProfiles($target->id,['ADMIN_CIF'],$target->id);
                $id = $target->id;
                $operation = 'SEGURANCA_MIGRACAO_ADMIN_CIF_NATIVO';
            } else {
                $id = $this->repository->createNativeUser($login,$email,$name,$department,$passwordHash,true,false);
                $this->repository->replaceProfiles($id,['ADMIN_CIF'],$id);
                $operation = 'SEGURANCA_BOOTSTRAP_ADMIN_CIF';
            }

            $user = $this->repository->findById($id);
            if ($user === null) {
                throw new RuntimeException('Falha ao recuperar o primeiro Administrador CIF após a configuração.');
            }
            $this->audit->log($operation,'usuario_cif',$user->id,$user->uuid,null,$origin,null,$this->auditSnapshot($user),'Configuração explícita do primeiro Administrador CIF independente do WordPress.');
            return $user;
        });
    }

    /** @param list<string> $profileCodes */
    public function createUser(
        string $login,
        string $email,
        string $name,
        string $department,
        string $password,
        array $profileCodes,
        CifUser $actor
    ): CifUser {
        [$login,$email,$name,$department] = $this->normalizeIdentity($login,$email,$name,$department);
        $profiles = $this->normalizeProfiles($profileCodes);
        $this->assertCanGrantProfiles($actor,$profiles);
        $hash = $this->passwords->hash($password);

        return $this->transactions->run(function () use ($login,$email,$name,$department,$hash,$profiles,$actor): CifUser {
            $this->repository->lockProfileByCode('ADMIN_CIF');
            $this->assertUniqueIdentity($login,$email,null);
            $id = $this->repository->createNativeUser($login,$email,$name,$department,$hash,true,true);
            $this->repository->replaceProfiles($id,$profiles,$actor->id);
            $user = $this->repository->findById($id);
            if ($user === null) {
                throw new RuntimeException('Falha ao recuperar o usuário CIF após a criação.');
            }
            $this->audit->log('SEGURANCA_USUARIO_CIF_CRIADO','usuario_cif',$user->id,$user->uuid,$actor,'WEB',null,$this->auditSnapshot($user));
            return $user;
        });
    }

    /** @param list<string> $profileCodes */
    public function updateUser(
        string $targetUuid,
        string $login,
        string $email,
        string $name,
        string $department,
        array $profileCodes,
        CifUser $actor
    ): CifUser {
        [$login,$email,$name,$department] = $this->normalizeIdentity($login,$email,$name,$department);
        $profiles = $this->normalizeProfiles($profileCodes);
        $this->assertCanGrantProfiles($actor,$profiles);

        return $this->transactions->run(function () use ($targetUuid,$login,$email,$name,$department,$profiles,$actor): CifUser {
            $this->repository->lockProfileByCode('ADMIN_CIF');
            $before = $this->requireTarget($targetUuid);
            $this->assertUniqueIdentity($login,$email,$before->id);
            if ($before->active && $before->hasProfile('ADMIN_CIF') && !in_array('ADMIN_CIF',$profiles,true) && $this->repository->countActiveAdmins() <= 1) {
                throw new RuntimeException('Não é permitido remover o perfil do último Administrador CIF ativo.');
            }
            $this->repository->updateIdentity($before->id,$login,$email,$name,$department);
            $this->repository->replaceProfiles($before->id,$profiles,$actor->id);
            $after = $this->requireTarget($targetUuid);
            $this->audit->log('SEGURANCA_USUARIO_CIF_ATUALIZADO','usuario_cif',$after->id,$after->uuid,$actor,'WEB',$this->auditSnapshot($before),$this->auditSnapshot($after));
            return $after;
        });
    }

    public function resetPassword(string $targetUuid, string $password, CifUser $actor): CifUser
    {
        $hash = $this->passwords->hash($password);
        return $this->transactions->run(function () use ($targetUuid,$hash,$actor): CifUser {
            $target = $this->requireTarget($targetUuid);
            $this->repository->setPasswordHash($target->id,$hash,true);
            $this->sessions->revokeUser($target->id);
            $after = $this->requireTarget($targetUuid);
            $this->audit->log('SEGURANCA_SENHA_USUARIO_REDEFINIDA','usuario_cif',$after->id,$after->uuid,$actor,'WEB',null,[
                'login'=>$after->login,'troca_obrigatoria'=>true,'sessoes_revogadas'=>true,
            ]);
            return $after;
        });
    }

    public function setActive(string $targetUuid, bool $active, CifUser $actor): CifUser
    {
        return $this->transactions->run(function () use ($targetUuid,$active,$actor): CifUser {
            $this->repository->lockProfileByCode('ADMIN_CIF');
            $target = $this->requireTarget($targetUuid);
            if (!$active && $target->id === $actor->id) {
                throw new RuntimeException('Você não pode inativar o próprio usuário durante a sessão atual.');
            }
            if (!$active && $target->active && $target->hasProfile('ADMIN_CIF') && $this->repository->countActiveAdmins() <= 1) {
                throw new RuntimeException('Não é permitido inativar o último Administrador CIF ativo.');
            }
            $this->repository->setActive($target->id,$active);
            if (!$active) {
                $this->sessions->revokeUser($target->id);
            }
            $after = $this->requireTarget($targetUuid);
            $this->audit->log($active?'SEGURANCA_USUARIO_CIF_ATIVADO':'SEGURANCA_USUARIO_CIF_INATIVADO','usuario_cif',$after->id,$after->uuid,$actor,'WEB',$this->auditSnapshot($target),$this->auditSnapshot($after));
            return $after;
        });
    }

    public function deleteUser(string $targetUuid, CifUser $actor): void
    {
        $this->transactions->run(function () use ($targetUuid,$actor): void {
            $this->repository->lockProfileByCode('ADMIN_CIF');
            $target = $this->requireTarget($targetUuid);
            if ($target->id === $actor->id) {
                throw new RuntimeException('Você não pode excluir o próprio usuário durante a sessão atual.');
            }
            if ($target->active && $target->hasProfile('ADMIN_CIF') && $this->repository->countActiveAdmins() <= 1) {
                throw new RuntimeException('Não é permitido excluir o último Administrador CIF ativo.');
            }
            if ($this->repository->hasHistoricalActivity($target->id)) {
                throw new RuntimeException('Este usuário já possui ações registradas no Sistema CIF. Para preservar a autoria e a auditoria, utilize Inativar usuário.');
            }
            $snapshot = $this->auditSnapshot($target);
            $this->sessions->revokeUser($target->id);
            $this->repository->hardDeleteUser($target->id);
            $this->audit->log('SEGURANCA_USUARIO_CIF_EXCLUIDO','usuario_cif',$target->id,$target->uuid,$actor,'WEB',$snapshot,null,'Exclusão definitiva permitida somente por ausência de histórico operacional.');
        });
    }

    private function assertUniqueIdentity(string $login, string $email, ?int $ignoreId): void
    {
        $sameLogin = $this->repository->findByLogin($login);
        if ($sameLogin !== null && $sameLogin->id !== $ignoreId) {
            throw new RuntimeException('Este login já está cadastrado no Sistema CIF.');
        }
        $sameEmail = $this->repository->findByEmail($email);
        if ($sameEmail !== null && $sameEmail->id !== $ignoreId) {
            throw new RuntimeException('Este e-mail já está cadastrado no Sistema CIF.');
        }
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function normalizeIdentity(string $login, string $email, string $name, string $department): array
    {
        $login = strtolower(trim($login));
        $email = strtolower(trim($email));
        $name = trim(preg_replace('/\s+/u',' ',$name) ?? $name);
        $department = trim(preg_replace('/\s+/u',' ',$department) ?? $department);
        if (preg_match('/^[a-z0-9._-]{3,100}$/',$login) !== 1) {
            throw new RuntimeException('O login deve ter de 3 a 100 caracteres e usar apenas letras, números, ponto, hífen ou sublinhado.');
        }
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new RuntimeException('Informe um e-mail válido para o usuário CIF.');
        }
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name,'UTF-8') : strlen($name);
        if ($nameLength < 3 || $nameLength > 200) {
            throw new RuntimeException('Informe o nome completo do usuário.');
        }
        $depLength = function_exists('mb_strlen') ? mb_strlen($department,'UTF-8') : strlen($department);
        if ($depLength < 2 || $depLength > 150) {
            throw new RuntimeException('Informe o setor do usuário.');
        }
        return [$login,$email,$name,$department];
    }

    /** @param list<string> $profileCodes @return list<string> */
    private function normalizeProfiles(array $profileCodes): array
    {
        $allowed = ['ADMIN_CIF','EDITOR_CIF','CONSULTA_CIF'];
        $profiles = [];
        foreach ($profileCodes as $code) {
            $normalized = strtoupper(trim((string)$code));
            if (in_array($normalized,$allowed,true)) {
                $profiles[$normalized] = true;
            }
        }
        $result = array_keys($profiles);
        if ($result === []) {
            throw new RuntimeException('Selecione um perfil CIF.');
        }
        return $result;
    }

    /** @param list<string> $profileCodes */
    private function assertCanGrantProfiles(CifUser $actor, array $profileCodes): void
    {
        if (!$actor->isPluginAdministrator()) {
            throw new RuntimeException('Somente um Administrador CIF pode administrar usuários e perfis.');
        }
        if (in_array('ADMIN_CIF',$profileCodes,true) && !$actor->hasProfile('ADMIN_CIF')) {
            throw new RuntimeException('Somente um Administrador CIF pode conceder o perfil Administrador CIF.');
        }
    }

    private function requireTarget(string $uuid): CifUser
    {
        $target = $this->repository->findByUuid(trim($uuid));
        if ($target === null) {
            throw new RuntimeException('Usuário CIF não localizado.');
        }
        return $target;
    }

    /** @return array<string,mixed> */
    private function auditSnapshot(CifUser $user): array
    {
        return [
            'login'=>$user->login,
            'nome'=>$user->name,
            'email'=>$user->email,
            'setor'=>$user->department,
            'ativo'=>$user->active,
            'perfis'=>$user->profiles,
            'troca_senha_obrigatoria'=>$user->mustChangePassword,
        ];
    }
}
