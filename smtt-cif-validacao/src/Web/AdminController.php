<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Web;

use SMTT\CIF\Validacao\Bootstrap\Environment;
use SMTT\CIF\Validacao\Database\MigrationRunner;
use SMTT\CIF\Validacao\Repository\PublicIssuanceRepository;
use SMTT\CIF\Validacao\Repository\SettingsRepository;
use SMTT\CIF\Validacao\Repository\SyncRepository;
use SMTT\CIF\Validacao\Service\RuntimeModeService;

final class AdminController
{
    private const PAGE = 'smtt-cif-validacao';

    public function __construct(
        private readonly Environment $environment,
        private readonly SettingsRepository $settings,
        private readonly SyncRepository $sync,
        private readonly PublicIssuanceRepository $issuances,
        private readonly MigrationRunner $migrations,
        private readonly RuntimeModeService $runtimeMode
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_smtt_cif_validacao_save_settings', [$this, 'saveSettings']);
        add_action('admin_post_smtt_cif_validacao_add_key', [$this, 'addKey']);
        add_action('admin_post_smtt_cif_validacao_retire_key', [$this, 'retireKey']);
    }

    public function menu(): void
    {
        add_menu_page(
            'SMTT CIF - Validação',
            'CIF Validação',
            'manage_options',
            self::PAGE,
            [$this, 'render'],
            'dashicons-shield-alt',
            58
        );
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::PAGE) {
            return;
        }
        wp_enqueue_style('smtt-cif-validacao-admin', SMTT_CIF_VALIDACAO_URL . 'assets/css/admin.css', [], SMTT_CIF_VALIDACAO_VERSION);
    }

    public function saveSettings(): void
    {
        $this->guard('smtt_cif_validacao_settings');
        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash((string)$_POST['mode'])) : '';
        $window = isset($_POST['timestamp_window']) ? (int)$_POST['timestamp_window'] : 300;
        if (!in_array($mode, [RuntimeModeService::MODE_LOCAL, RuntimeModeService::MODE_PRODUCTION], true) || $window < 60 || $window > 900) {
            $this->redirect('invalid_settings');
        }

        $currentMode = $this->runtimeMode->mode();
        if ($mode === RuntimeModeService::MODE_PRODUCTION) {
            if (!$this->runtimeMode->productionSiteIsSecure()) {
                $this->redirect('production_requires_https');
            }
            $confirmed = isset($_POST['confirm_production']) && (string)$_POST['confirm_production'] === '1';
            if ($currentMode !== RuntimeModeService::MODE_PRODUCTION && !$confirmed) {
                $this->redirect('production_confirmation_required');
            }
        }

        $this->runtimeMode->save($mode);
        $this->settings->set('security.timestamp_window_seconds', $window, 'Janela máxima de diferença do timestamp assinado.');
        $this->redirect('settings_saved');
    }

    public function addKey(): void
    {
        $this->guard('smtt_cif_validacao_add_key');
        $keyId = isset($_POST['key_id']) ? trim(sanitize_text_field(wp_unslash((string)$_POST['key_id']))) : '';
        $publicBase64 = isset($_POST['public_key']) ? trim((string)wp_unslash($_POST['public_key'])) : '';
        if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $keyId) !== 1) {
            $this->redirect('invalid_key_id');
        }
        if (!$this->runtimeMode->keyIdCompatible($keyId)) {
            $this->redirect('key_environment_mismatch');
        }
        $public = base64_decode($publicBase64, true);
        if (!is_string($public) || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $this->redirect('invalid_public_key');
        }
        $this->sync->installKey($keyId, $public);
        if (function_exists('sodium_memzero')) {
            sodium_memzero($public);
        }
        $this->redirect('key_added');
    }

    public function retireKey(): void
    {
        $this->guard('smtt_cif_validacao_retire_key');
        $keyId = isset($_POST['key_id']) ? trim(sanitize_text_field(wp_unslash((string)$_POST['key_id']))) : '';
        if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $keyId) !== 1) {
            $this->redirect('invalid_key_id');
        }
        $this->sync->retireKey($keyId);
        $this->redirect('key_retired');
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }
        $db = $this->environment->database();
        $mode = $this->runtimeMode->mode();
        $syncEnvironment = $this->runtimeMode->syncEnvironment();
        $productionReady = $this->runtimeMode->productionSiteIsSecure();
        $window = (int)$this->settings->get('security.timestamp_window_seconds', 300);
        $keys = $this->sync->keys();
        $logs = $this->sync->recentLogs(20);
        $notice = isset($_GET['cif_notice']) ? sanitize_key(wp_unslash((string)$_GET['cif_notice'])) : '';
        ?>
        <div class="wrap cif-admin-wrap">
            <h1>SMTT CIF - Validação</h1>
            <p class="description">Painel técnico do portal público. As projeções recebidas da intranet não podem ser editadas manualmente.</p>
            <?= $this->notice($notice) ?>

            <div class="cif-mode-banner <?= $mode === RuntimeModeService::MODE_PRODUCTION ? 'is-production' : 'is-local' ?>">
                <strong><?= esc_html($this->runtimeMode->label()) ?></strong>
                <span><?= $mode === RuntimeModeService::MODE_PRODUCTION
                    ? 'Recebe somente sincronizações marcadas como production. A consulta pública não exibe aviso de homologação.'
                    : 'Modo seguro para desenvolvimento e testes. Recebe somente homologation e a consulta pública exibe aviso de ambiente de teste.' ?></span>
            </div>

            <div class="cif-admin-grid">
                <section class="cif-admin-card">
                    <h2>Diagnóstico</h2>
                    <dl>
                        <div><dt>Plugin</dt><dd><?= esc_html(SMTT_CIF_VALIDACAO_VERSION) ?></dd></div>
                        <div><dt>Banco público</dt><dd><code><?= esc_html($db['name']) ?></code></dd></div>
                        <div><dt>Schema</dt><dd><code><?= esc_html($this->migrations->latestVersion()) ?></code></dd></div>
                        <div><dt>Modo</dt><dd><strong><?= esc_html($this->runtimeMode->label()) ?></strong></dd></div>
                        <div><dt>Environment assinado</dt><dd><code><?= esc_html($syncEnvironment) ?></code></dd></div>
                        <div><dt>HTTPS para produção</dt><dd><?= $productionReady ? 'OK' : 'Não disponível nesta URL' ?></dd></div>
                        <div><dt>Projeções</dt><dd><?= esc_html((string)$this->issuances->count()) ?></dd></div>
                        <div><dt>Última sincronização</dt><dd><?= esc_html($this->issuances->lastSyncAt() ?? 'Ainda não recebida') ?></dd></div>
                        <div><dt>Replay guard</dt><dd><?= esc_html((string)$this->sync->replayCount()) ?> request IDs</dd></div>
                    </dl>
                </section>

                <section class="cif-admin-card">
                    <h2>Protocolo</h2>
                    <p>Endpoints esperados pelo SMTT CIF - Gestão:</p>
                    <code>POST /wp-json/smtt-cif/v1/sync/events</code><br>
                    <code>POST /wp-json/smtt-cif/v1/sync/heartbeat</code><br>
                    <code>POST /wp-json/smtt-cif/v1/sync/reconcile</code>
                    <p class="cif-admin-note">Protocolo v1 · Ed25519 · SHA-256 · anti-replay por request_id.</p>
                </section>
            </div>

            <section class="cif-admin-card">
                <h2>Ambiente e segurança</h2>
                <p>Use <strong>Local / Homologação</strong> durante todo o desenvolvimento. Mude para <strong>Produção</strong> somente no portal externo definitivo.</p>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                    <input type="hidden" name="action" value="smtt_cif_validacao_save_settings">
                    <?php wp_nonce_field('smtt_cif_validacao_settings'); ?>
                    <fieldset class="cif-mode-switch">
                        <legend>Modo do plugin</legend>
                        <label class="cif-mode-option">
                            <input type="radio" name="mode" value="local" <?= checked($mode, RuntimeModeService::MODE_LOCAL, false) ?>>
                            <span><strong>LOCAL / HOMOLOGAÇÃO</strong><small>Para o WordPress local com Gestão + Validação no mesmo site.</small></span>
                        </label>
                        <label class="cif-mode-option">
                            <input type="radio" name="mode" value="production" <?= checked($mode, RuntimeModeService::MODE_PRODUCTION, false) ?>>
                            <span><strong>PRODUÇÃO</strong><small>Para o portal público real; exige HTTPS.</small></span>
                        </label>
                    </fieldset>
                    <table class="form-table" role="presentation">
                        <tr><th><label for="timestamp_window">Janela de timestamp</label></th><td>
                            <input id="timestamp_window" name="timestamp_window" type="number" min="60" max="900" value="<?= esc_attr((string)$window) ?>"> segundos
                            <p class="description">Recomendado: 300 segundos. Protege contra replay com relógios ligeiramente dessincronizados.</p>
                        </td></tr>
                    </table>
                    <label class="cif-production-confirm">
                        <input type="checkbox" name="confirm_production" value="1">
                        <span>Confirmo que, ao selecionar Produção pela primeira vez, esta instalação é o portal público definitivo e está corretamente publicada em HTTPS.</span>
                    </label>
                    <?php submit_button('Salvar ambiente e segurança'); ?>
                </form>
            </section>

            <section class="cif-admin-card">
                <h2>Chaves públicas Ed25519 aceitas</h2>
                <p>Instale aqui somente a <strong>chave pública</strong> gerada no Gestão para o mesmo ambiente. A chave privada nunca deve sair da intranet.</p>
                <p class="cif-admin-note">Ambiente atual: <strong><?= esc_html($this->runtimeMode->label()) ?></strong> · espera <code><?= esc_html($syncEnvironment) ?></code>.</p>
                <form class="cif-key-form" method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                    <input type="hidden" name="action" value="smtt_cif_validacao_add_key">
                    <?php wp_nonce_field('smtt_cif_validacao_add_key'); ?>
                    <label>Key ID <input name="key_id" type="text" maxlength="100" required placeholder="<?= $mode === RuntimeModeService::MODE_PRODUCTION ? 'smtt-cif-prod-...' : 'smtt-cif-hml-...' ?>"></label>
                    <label>Chave pública Base64 <textarea name="public_key" rows="3" required spellcheck="false"></textarea></label>
                    <?php submit_button('Adicionar / aceitar chave', 'secondary', 'submit', false); ?>
                </form>

                <table class="widefat striped cif-key-table">
                    <thead><tr><th>Key ID</th><th>Fingerprint SHA-256</th><th>Status</th><th>Ambiente</th><th>Vigência</th><th>Ação</th></tr></thead>
                    <tbody>
                    <?php if ($keys === []): ?>
                        <tr><td colspan="6">Nenhuma chave pública instalada.</td></tr>
                    <?php else: foreach ($keys as $key):
                        $keyId = (string)$key['key_id'];
                        $hint = $this->runtimeMode->keyEnvironmentHint($keyId);
                        $compatible = $this->runtimeMode->keyIdCompatible($keyId);
                        ?>
                        <tr>
                            <td><code><?= esc_html($keyId) ?></code></td>
                            <td><code><?= esc_html((string)$key['fingerprint']) ?></code></td>
                            <td><?= esc_html((string)$key['status']) ?></td>
                            <td><?= esc_html($hint ?? 'não identificado') ?><?= !$compatible ? ' — incompatível' : '' ?></td>
                            <td><?= esc_html((string)$key['valid_from']) ?><?= !empty($key['valid_until']) ? ' → ' . esc_html((string)$key['valid_until']) : '' ?></td>
                            <td>
                                <?php if ((string)$key['status'] === 'ACCEPTED'): ?>
                                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                                    <input type="hidden" name="action" value="smtt_cif_validacao_retire_key">
                                    <input type="hidden" name="key_id" value="<?= esc_attr($keyId) ?>">
                                    <?php wp_nonce_field('smtt_cif_validacao_retire_key'); ?>
                                    <button class="button button-small" type="submit">Retirar aceitação</button>
                                </form>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </section>

            <section class="cif-admin-card">
                <h2>Últimos registros técnicos</h2>
                <table class="widefat striped">
                    <thead><tr><th>Data UTC</th><th>Endpoint</th><th>Resultado</th><th>HTTP</th><th>Request ID</th><th>Resumo</th></tr></thead>
                    <tbody>
                    <?php if ($logs === []): ?>
                        <tr><td colspan="6">Ainda não há requisições registradas.</td></tr>
                    <?php else: foreach ($logs as $log): ?>
                        <tr>
                            <td><?= esc_html((string)$log['occurred_at']) ?></td>
                            <td><?= esc_html((string)$log['endpoint']) ?></td>
                            <td><?= esc_html((string)$log['result']) ?></td>
                            <td><?= esc_html((string)$log['http_status']) ?></td>
                            <td><code><?= esc_html((string)($log['request_id'] ?? '—')) ?></code></td>
                            <td><?= esc_html((string)($log['summary'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    }

    private function guard(string $nonceAction): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }
        check_admin_referer($nonceAction);
    }

    private function redirect(string $notice): never
    {
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'cif_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    private function notice(string $code): string
    {
        $messages = [
            'settings_saved' => ['success', 'Ambiente e configuração de segurança salvos.'],
            'key_added' => ['success', 'Chave pública aceita pelo portal.'],
            'key_retired' => ['success', 'A chave deixou de ser aceita.'],
            'invalid_settings' => ['error', 'Configuração inválida.'],
            'production_requires_https' => ['error', 'O modo Produção exige que Home URL e Site URL do WordPress utilizem HTTPS.'],
            'production_confirmation_required' => ['error', 'Confirme explicitamente a mudança para Produção antes de salvar.'],
            'invalid_key_id' => ['error', 'Key ID inválido.'],
            'key_environment_mismatch' => ['error', 'O Key ID informado pertence claramente ao outro ambiente. Gere/use a chave correspondente ao ambiente atual.'],
            'invalid_public_key' => ['error', 'A chave pública deve ser Ed25519 válida com 32 bytes em Base64.'],
        ];
        if (!isset($messages[$code])) {
            return '';
        }
        [$class, $text] = $messages[$code];
        return '<div class="notice notice-' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }
}
