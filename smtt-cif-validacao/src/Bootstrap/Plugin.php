<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Bootstrap;

use SMTT\CIF\Validacao\Web\PublicValidationController;

final class Plugin
{
    private static ?Container $container = null;
    private static ?string $installationError = null;

    public static function boot(): void
    {
        self::$container = new Container();
        self::$container->environment()->assertRuntime();

        // Registro leve: a conexão com o banco público só é aberta quando uma
        // rota CIF, endpoint de sync ou tela administrativa realmente precisar dela.
        add_action('rest_api_init', [self::class, 'registerRestRoutes']);
        add_action('init', [PublicValidationController::class, 'addRewriteRule']);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'smtt_cif_token';
            return $vars;
        });
        add_action('template_redirect', [self::class, 'maybeRenderPublicValidation'], 0);

        if (is_admin()) {
            add_action('admin_init', [self::class, 'ensureInstalledAfterUpdate'], 1);
            add_action('admin_menu', static function (): void { self::container()->admin()->menu(); });
            add_action('admin_enqueue_scripts', static function (string $hook): void { self::container()->admin()->assets($hook); });
            add_action('admin_post_smtt_cif_validacao_save_settings', static function (): void { self::container()->admin()->saveSettings(); });
            add_action('admin_post_smtt_cif_validacao_add_key', static function (): void { self::container()->admin()->addKey(); });
            add_action('admin_post_smtt_cif_validacao_retire_key', static function (): void { self::container()->admin()->retireKey(); });
            add_action('admin_notices', [self::class, 'renderInstallationError']);
            add_filter('plugin_action_links_' . plugin_basename(SMTT_CIF_VALIDACAO_FILE), [self::class, 'pluginActionLinks']);
        }
    }

    public static function registerRestRoutes(): void
    {
        register_rest_route('smtt-cif/v1', '/sync/events', [
            'methods' => 'POST',
            'callback' => [self::class, 'restEvents'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('smtt-cif/v1', '/sync/heartbeat', [
            'methods' => 'POST',
            'callback' => [self::class, 'restHeartbeat'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('smtt-cif/v1', '/sync/reconcile', [
            'methods' => 'POST',
            'callback' => [self::class, 'restReconcile'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function restEvents(\WP_REST_Request $request): \WP_REST_Response
    {
        return self::dispatchRest(static fn(): \WP_REST_Response => self::container()->rest()->events($request));
    }

    public static function restHeartbeat(\WP_REST_Request $request): \WP_REST_Response
    {
        return self::dispatchRest(static fn(): \WP_REST_Response => self::container()->rest()->heartbeat($request));
    }

    public static function restReconcile(\WP_REST_Request $request): \WP_REST_Response
    {
        return self::dispatchRest(static fn(): \WP_REST_Response => self::container()->rest()->reconcile($request));
    }

    public static function maybeRenderPublicValidation(): void
    {
        $token = (string)get_query_var('smtt_cif_token', '');
        if ($token === '') {
            return;
        }
        try {
            self::container()->publicController()->maybeRender();
        } catch (\Throwable $e) {
            error_log('[SMTT CIF Validação] Falha na consulta pública: ' . $e->getMessage());
            status_header(503);
            nocache_headers();
            header('Cache-Control: no-store, max-age=0');
            header('X-Robots-Tag: noindex, nofollow, noarchive', true);
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>SMTT CIF</title></head><body><main><h1>Validação temporariamente indisponível</h1><p>Não foi possível consultar o Sistema CIF neste momento.</p></main></body></html>';
            exit;
        }
    }

    private static function dispatchRest(\Closure $callback): \WP_REST_Response
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            error_log('[SMTT CIF Validação] Falha de infraestrutura REST: ' . $e->getMessage());
            $response = new \WP_REST_Response(['result' => 'ERROR', 'code' => 'SERVICE_UNAVAILABLE'], 503);
            $response->header('Cache-Control', 'no-store, max-age=0');
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('Referrer-Policy', 'no-referrer');
            return $response;
        }
    }

    private static function container(): Container
    {
        if (self::$container === null) {
            throw new \LogicException('SMTT CIF - Validação não inicializado.');
        }
        return self::$container;
    }

    public static function activate(): void
    {
        $container = new Container();
        $container->environment()->assertRuntime();
        $container->installer()->ensureInstalled();
        PublicValidationController::addRewriteRule();
        flush_rewrite_rules(false);
        update_option('smtt_cif_validacao_migrated_version', SMTT_CIF_VALIDACAO_VERSION, false);
    }

    public static function ensureInstalledAfterUpdate(): void
    {
        if (self::$container === null) {
            return;
        }
        // Evita abrir o banco público e varrer migrations em todo admin request.
        if ((string) get_option('smtt_cif_validacao_migrated_version', '') === SMTT_CIF_VALIDACAO_VERSION) {
            return;
        }
        try {
            self::$container->installer()->ensureInstalled();
            update_option('smtt_cif_validacao_migrated_version', SMTT_CIF_VALIDACAO_VERSION, false);
            self::$installationError = null;
        } catch (\Throwable $e) {
            self::$installationError = $e->getMessage();
            error_log('[SMTT CIF Validação] Falha ao preparar banco/migrations: ' . $e->getMessage());
        }
    }

    /** @param array<int|string,string> $links */
    public static function pluginActionLinks(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=smtt-cif-validacao')) . '">Configurar</a>');
        return $links;
    }

    public static function renderInstallationError(): void
    {
        if (self::$installationError === null || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>' . esc_html('SMTT CIF - Validação: ' . self::$installationError) . '</strong></p></div>';
    }
}
