<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web\Controller;

use RuntimeException;
use SMTT\CIF\Gestao\Security\AccessGate;
use SMTT\CIF\Gestao\Security\AuthorizationException;
use SMTT\CIF\Gestao\Security\CurrentUser;
use SMTT\CIF\Gestao\Service\AuthenticationService;
use SMTT\CIF\Gestao\Web\View;

final class PasswordController
{
    public function __construct(
        private readonly View $view,
        private readonly AccessGate $gate,
        private readonly AuthenticationService $authentication,
        private readonly CurrentUser $currentUser
    ) {
    }

    public function handle(): never
    {
        try {
            $user = $this->gate->requireUser();
        } catch (AuthorizationException) {
            wp_safe_redirect(home_url('/cif/login'));
            exit;
        }

        $error = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $nonce = isset($_POST['smtt_cif_nonce']) ? (string)wp_unslash($_POST['smtt_cif_nonce']) : '';
            if (!\smtt_cif_verify_nonce($nonce, 'smtt_cif_change_own_password')) {
                $error = 'A sessão do formulário expirou. Atualize a página e tente novamente.';
            } else {
                $current = isset($_POST['current_password']) ? (string)wp_unslash($_POST['current_password']) : '';
                $new = isset($_POST['new_password']) ? (string)wp_unslash($_POST['new_password']) : '';
                $confirm = isset($_POST['confirm_password']) ? (string)wp_unslash($_POST['confirm_password']) : '';
                try {
                    if (!hash_equals($new, $confirm)) {
                        throw new RuntimeException('A confirmação da nova senha não confere.');
                    }
                    $this->authentication->changeOwnPassword($user, $current, $new);
                    $this->currentUser->reset();
                    wp_safe_redirect(add_query_arg('password_changed', '1', home_url('/cif/login')));
                    exit;
                } catch (RuntimeException $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $this->view->render('security/change-password', [
            'title' => 'Alterar senha',
            'currentUser' => $user,
            'activeNav' => '',
            'error' => $error,
        ], $error === null ? 200 : 422);
    }
}
