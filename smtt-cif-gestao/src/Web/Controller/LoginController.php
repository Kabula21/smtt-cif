<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use RuntimeException;
use SMTT\CIF\Gestao\Security\CsrfToken;
use SMTT\CIF\Gestao\Security\CurrentUser;
use SMTT\CIF\Gestao\Service\AuthenticationService;
use SMTT\CIF\Gestao\Web\View;

final class LoginController
{
    public function __construct(
        private readonly View $view,
        private readonly CurrentUser $currentUser,
        private readonly AuthenticationService $authentication,
        private readonly CsrfToken $csrf
    ) {
    }

    public function handle(): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->login();
        }

        $existing = $this->currentUser->get();
        if ($existing !== null) {
            $this->redirect(home_url($existing->mustChangePassword ? '/cif/minha-senha' : '/cif'));
        }

        $message = null;
        if (isset($_GET['loggedout']) && (string)$_GET['loggedout'] === '1') {
            $message = 'Sessão do Sistema CIF encerrada.';
        } elseif (isset($_GET['setup']) && (string)$_GET['setup'] === '1') {
            $message = 'Administrador CIF configurado. Entre com o login e a senha definidos pela TI.';
        } elseif (isset($_GET['password_changed']) && (string)$_GET['password_changed'] === '1') {
            $message = 'Senha alterada com sucesso. Entre novamente.';
        }

        $this->view->render('login/form', [
            'title' => 'Entrar no Sistema CIF',
            'error' => null,
            'message' => $message,
            'username' => '',
        ]);
    }

    public function logout(): never
    {
        $nonce = isset($_GET['_cifcsrf']) ? (string)$_GET['_cifcsrf'] : '';
        if (!\smtt_cif_verify_nonce($nonce, 'smtt_cif_logout')) {
            $this->view->render('error/forbidden', [
                'title' => 'Operação não autorizada',
                'message' => 'A solicitação de saída é inválida ou expirou.',
                'currentUser' => $this->currentUser->get(),
            ], 403);
        }

        $user = $this->currentUser->get();
        $this->authentication->logout($user);
        $this->currentUser->reset();
        $this->csrf->rotate();
        $this->redirect(add_query_arg('loggedout', '1', home_url('/cif/login')));
    }

    private function login(): never
    {
        $nonce = isset($_POST['smtt_cif_nonce']) ? (string)wp_unslash($_POST['smtt_cif_nonce']) : '';
        if (!\smtt_cif_verify_nonce($nonce, 'smtt_cif_login')) {
            $this->renderError('A sessão do formulário expirou. Atualize a página e tente novamente.', '', 403);
        }

        $username = isset($_POST['user_login']) ? sanitize_text_field((string)wp_unslash($_POST['user_login'])) : '';
        $password = isset($_POST['user_password']) ? (string)wp_unslash($_POST['user_password']) : '';
        $remember = isset($_POST['remember']) && (string)$_POST['remember'] === '1';

        try {
            $user = $this->authentication->login($username, $password, $remember);
        } catch (RuntimeException $e) {
            $this->renderError($e->getMessage(), $username, 401);
        }

        $this->currentUser->reset();
        $this->redirect(home_url($user->mustChangePassword ? '/cif/minha-senha' : '/cif'));
    }

    private function renderError(string $message, string $username, int $status): never
    {
        $this->view->render('login/form', [
            'title' => 'Entrar no Sistema CIF',
            'error' => $message,
            'message' => null,
            'username' => $username,
        ], $status);
    }

    private function redirect(string $url): never
    {
        wp_safe_redirect($url);
        exit;
    }
}
