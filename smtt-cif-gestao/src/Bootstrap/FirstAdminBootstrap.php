<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Bootstrap;

use SMTT\CIF\Gestao\Repository\AuthorizationRepository;
use SMTT\CIF\Gestao\Service\UserAccessService;

final class FirstAdminBootstrap
{
    public function __construct(
        private readonly AuthorizationRepository $authorization,
        private readonly UserAccessService $userAccess
    ) {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'renderNotice']);
        add_action('admin_post_smtt_cif_bootstrap_admin', [$this, 'handle']);
    }

    public function renderNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        try {
            if ($this->authorization->countConfiguredAdmins() > 0) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $wpUser = wp_get_current_user();
        $legacy = $this->authorization->firstUnconfiguredAdmin();
        $login = $legacy?->login ?? (($wpUser instanceof \WP_User) ? (string)$wpUser->user_login : '');
        $name = $legacy?->name ?? (($wpUser instanceof \WP_User) ? (string)$wpUser->display_name : '');
        $email = $legacy?->email ?? (($wpUser instanceof \WP_User) ? (string)$wpUser->user_email : '');

        echo '<div class="notice notice-warning"><p><strong>SMTT CIF - Gestão:</strong> configure as credenciais próprias do primeiro Administrador CIF. ';
        echo 'Esta conta ficará no banco <code>smtt_cif</code> e será independente de <code>wp_users</code>.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:10px;max-width:850px;margin:12px 0">';
        echo '<input type="hidden" name="action" value="smtt_cif_bootstrap_admin">';
        wp_nonce_field('smtt_cif_bootstrap_admin', 'smtt_cif_bootstrap_nonce');
        echo '<label>Login CIF<br><input class="regular-text" name="cif_login" required value="' . esc_attr($login) . '"></label>';
        echo '<label>Nome completo<br><input class="regular-text" name="cif_name" required value="' . esc_attr($name) . '"></label>';
        echo '<label>E-mail<br><input class="regular-text" type="email" name="cif_email" required value="' . esc_attr($email) . '"></label>';
        echo '<label>Setor<br><input class="regular-text" name="cif_department" required placeholder="Ex.: Recursos Humanos"></label>';
        echo '<label>Senha CIF<br><input class="regular-text" type="password" name="cif_password" minlength="10" required autocomplete="new-password"></label>';
        echo '<label>Confirmar senha<br><input class="regular-text" type="password" name="cif_password_confirm" minlength="10" required autocomplete="new-password"></label>';
        echo '<div style="grid-column:1/-1">';
        submit_button('Configurar Administrador CIF independente', 'primary', 'submit', false);
        echo '</div></form></div>';
    }

    public function handle(): never
    {
        if (!current_user_can('activate_plugins')) {
            wp_die('Você não possui autorização para inicializar o Administrador CIF.', 'SMTT CIF - Gestão', ['response'=>403]);
        }
        check_admin_referer('smtt_cif_bootstrap_admin', 'smtt_cif_bootstrap_nonce');

        $get = static fn(string $key): string => isset($_POST[$key]) ? sanitize_text_field((string)wp_unslash($_POST[$key])) : '';
        $login = $get('cif_login');
        $name = $get('cif_name');
        $email = isset($_POST['cif_email']) ? sanitize_email((string)wp_unslash($_POST['cif_email'])) : '';
        $department = $get('cif_department');
        $password = isset($_POST['cif_password']) ? (string)wp_unslash($_POST['cif_password']) : '';
        $confirm = isset($_POST['cif_password_confirm']) ? (string)wp_unslash($_POST['cif_password_confirm']) : '';

        if (!hash_equals($password, $confirm)) {
            wp_die('A confirmação da senha não confere.', 'SMTT CIF - Gestão', ['response'=>422,'back_link'=>true]);
        }

        try {
            $this->userAccess->bootstrapAdmin($login,$email,$name,$department,$password,'BOOTSTRAP_WP_ADMIN');
        } catch (\Throwable $e) {
            wp_die(esc_html($e->getMessage()), 'SMTT CIF - Gestão', ['response'=>409,'back_link'=>true]);
        }

        wp_safe_redirect(add_query_arg('setup','1',home_url('/cif/login')));
        exit;
    }
}
