<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use RuntimeException;
use SMTT\CIF\Gestao\Domain\Authorization\PermissionCodes;
use SMTT\CIF\Gestao\Repository\AuthorizationRepository;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Service\UserAccessService;
use SMTT\CIF\Gestao\Web\View;

final class UserAccessController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly AuthorizationRepository $authorization,
        private readonly UserAccessService $userAccess
    ) {
    }

    public function handle(): never
    {
        try {
            $user = $this->gate->requireSystemAdministrator();
        } catch (AuthorizationException $e) {
            $this->forbidden($e->getMessage());
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->handlePost($user);
        }

        $this->renderList($user, $this->noticeMessage(isset($_GET['notice']) ? (string)$_GET['notice'] : ''), null);
    }

    public function remove(string $targetUuid): never
    {
        try {
            $actor = $this->gate->requireSystemAdministrator();
        } catch (AuthorizationException $e) {
            $this->forbidden($e->getMessage());
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->forbidden('Operação inválida.');
        }
        $nonce = isset($_POST['smtt_cif_nonce']) ? (string)wp_unslash($_POST['smtt_cif_nonce']) : '';
        if (!\smtt_cif_verify_nonce($nonce, 'smtt_cif_delete_user_' . $targetUuid)) {
            $this->forbidden('A sessão da operação expirou. Atualize a página e tente novamente.');
        }
        try {
            $this->userAccess->deleteUser($targetUuid,$actor);
            $this->redirectNotice('user_deleted');
        } catch (RuntimeException $e) {
            $this->renderList($actor,null,$e->getMessage(),422);
        }
    }

    private function handlePost(CifUser $actor): never
    {
        $nonce = isset($_POST['smtt_cif_nonce']) ? (string)wp_unslash($_POST['smtt_cif_nonce']) : '';
        if (!\smtt_cif_verify_nonce($nonce, 'smtt_cif_manage_users')) {
            $this->forbidden('A sessão do formulário expirou. Atualize a página e tente novamente.');
        }
        $operation = isset($_POST['operation']) ? sanitize_key((string)wp_unslash($_POST['operation'])) : '';
        try {
            if ($operation === 'create') {
                [$login,$email,$name,$department] = $this->identityFields();
                $password = isset($_POST['password']) ? (string)wp_unslash($_POST['password']) : '';
                $confirm = isset($_POST['password_confirm']) ? (string)wp_unslash($_POST['password_confirm']) : '';
                if (!hash_equals($password,$confirm)) {
                    throw new RuntimeException('A confirmação da senha inicial não confere.');
                }
                $this->userAccess->createUser($login,$email,$name,$department,$password,$this->postedProfiles(),$actor);
                $this->redirectNotice('user_created');
            }
            if ($operation === 'update') {
                $uuid = $this->targetUuid();
                [$login,$email,$name,$department] = $this->identityFields();
                $this->userAccess->updateUser($uuid,$login,$email,$name,$department,$this->postedProfiles(),$actor);
                $this->redirectNotice('user_updated');
            }
            if ($operation === 'status') {
                $uuid = $this->targetUuid();
                $active = isset($_POST['active']) && (string)$_POST['active'] === '1';
                $this->userAccess->setActive($uuid,$active,$actor);
                $this->redirectNotice($active?'user_activated':'user_deactivated');
            }
            if ($operation === 'reset_password') {
                $uuid = $this->targetUuid();
                $password = isset($_POST['password']) ? (string)wp_unslash($_POST['password']) : '';
                $confirm = isset($_POST['password_confirm']) ? (string)wp_unslash($_POST['password_confirm']) : '';
                if (!hash_equals($password,$confirm)) {
                    throw new RuntimeException('A confirmação da nova senha não confere.');
                }
                $this->userAccess->resetPassword($uuid,$password,$actor);
                $this->redirectNotice('password_reset');
            }
            if ($operation === 'delete') {
                $uuid = $this->targetUuid();
                $confirmation = isset($_POST['confirm_delete']) ? trim((string)wp_unslash($_POST['confirm_delete'])) : '';
                if ($confirmation !== 'EXCLUIR') {
                    throw new RuntimeException('Digite EXCLUIR para confirmar a exclusão definitiva.');
                }
                $this->userAccess->deleteUser($uuid,$actor);
                $this->redirectNotice('user_deleted');
            }
            throw new RuntimeException('Operação de segurança inválida.');
        } catch (RuntimeException $e) {
            $this->renderList($actor,null,$e->getMessage(),422);
        }
    }

    private function renderList(CifUser $user, ?array $notice, ?string $error, int $status=200): never
    {
        $list = $this->userListState();
        $deletable = [];
        foreach ($list['users'] as $managed) {
            $deletable[$managed->id] = !$this->authorization->hasHistoricalActivity($managed->id);
        }
        $this->view->render('security/users', [
            'title'=>'Usuários e acessos', 'currentUser'=>$user, 'activeNav'=>'security-users',
            'users'=>$list['users'], 'profiles'=>$this->authorization->listActiveProfiles(),
            'canManage'=>$user->can(PermissionCodes::SEGURANCA_GERENCIAR_USUARIOS),
            'permissionMatrix'=>$this->authorization->permissionMatrix(), 'deletable'=>$deletable,
            'notice'=>$notice, 'error'=>$error, 'q'=>$list['q'], 'total'=>$list['total'],
            'page'=>$list['page'], 'pages'=>$list['pages'], 'perPage'=>$list['perPage'],
        ], $status);
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function identityFields(): array
    {
        $get = static fn(string $key): string => isset($_POST[$key]) ? sanitize_text_field((string)wp_unslash($_POST[$key])) : '';
        $login = $get('login');
        $email = isset($_POST['email']) ? sanitize_email((string)wp_unslash($_POST['email'])) : '';
        return [$login,$email,$get('name'),$get('department')];
    }

    private function targetUuid(): string
    {
        return isset($_POST['target_uuid']) ? sanitize_text_field((string)wp_unslash($_POST['target_uuid'])) : '';
    }

    /** @return list<string> */
    private function postedProfiles(): array
    {
        if (isset($_POST['profile'])) {
            return [sanitize_text_field((string)wp_unslash($_POST['profile']))];
        }
        $raw = $_POST['profiles'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_map(static fn($v): string => sanitize_text_field((string)wp_unslash($v)),$raw));
    }

    /** @return array{users:list<CifUser>,q:string,total:int,page:int,pages:int,perPage:int} */
    private function userListState(): array
    {
        $q = isset($_GET['q']) ? sanitize_text_field((string)wp_unslash($_GET['q'])) : '';
        $page = isset($_GET['pagina']) ? max(1,(int)$_GET['pagina']) : 1;
        $perPage = isset($_GET['por_pagina']) ? (int)$_GET['por_pagina'] : 25;
        $perPage = in_array($perPage,[25,50,100],true) ? $perPage : 25;
        $result = $this->authorization->searchUsers($q,$page,$perPage);
        $pages = max(1,(int)ceil($result['total']/$perPage));
        if ($page>$pages) {
            $page=$pages; $result=$this->authorization->searchUsers($q,$page,$perPage);
        }
        return ['users'=>$result['items'],'q'=>$q,'total'=>$result['total'],'page'=>$page,'pages'=>$pages,'perPage'=>$perPage];
    }

    private function redirectNotice(string $notice): never
    {
        wp_safe_redirect(add_query_arg('notice',rawurlencode($notice),home_url('/cif/seguranca/usuarios')));
        exit;
    }

    /** @return array{type:string,text:string}|null */
    private function noticeMessage(string $code): ?array
    {
        return match($code) {
            'user_created'=>['type'=>'success','text'=>'Usuário CIF criado. A senha inicial deverá ser alterada no primeiro acesso.'],
            'user_updated'=>['type'=>'success','text'=>'Dados e perfil do usuário atualizados.'],
            'user_activated'=>['type'=>'success','text'=>'Usuário CIF reativado.'],
            'user_deactivated'=>['type'=>'success','text'=>'Usuário CIF inativado e suas sessões foram encerradas.'],
            'password_reset'=>['type'=>'success','text'=>'Senha redefinida. As sessões foram encerradas e a troca será exigida no próximo acesso.'],
            'user_deleted'=>['type'=>'success','text'=>'Usuário CIF excluído definitivamente.'],
            default=>null,
        };
    }

    private function forbidden(string $message): never
    {
        if ($this->gate->user() === null) {
            wp_safe_redirect(home_url('/cif/login'));
            exit;
        }
        $this->view->render('error/forbidden',['title'=>'Acesso negado','message'=>$message,'currentUser'=>$this->gate->user()],403);
    }
}
