<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Bootstrap;

use SMTT\CIF\Gestao\Command\CommandRegistrar;

final class Plugin
{
    private const RUNTIME_VERSION_OPTION = 'smtt_cif_gestao_runtime_version';
    private static ?Container $container = null;
    private static ?string $installationError = null;

    public static function boot(): void
    {
        self::$container = new Container();
        self::$container->environment()->assertRuntime();

        // O worker de sincronização só precisa materializar seus callbacks em requisições
        // de cron. Em navegação humana do /cif não registramos a árvore do worker.
        // O filtro de intervalos é leve e continua disponível para agendamento/ativação.
        add_filter('cron_schedules', [self::$container->syncScheduler(), 'cronSchedules']);
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            add_action(\SMTT\CIF\Gestao\Sync\SyncScheduler::QUEUE_HOOK, [self::$container->syncScheduler(), 'runQueue']);
            add_action(\SMTT\CIF\Gestao\Sync\SyncScheduler::RECONCILE_HOOK, [self::$container->syncScheduler(), 'runReconciliation']);
        }

        // Fast path: não consulta/regrava o cron em todo request. A manutenção
        // estrutural só roda uma vez por versão do plugin.
        add_action('init', [self::class, 'ensureRuntimeVersion'], 1);
        add_action('init', [self::class, 'ensureInstalledForCifUpdate'], 2);

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            (new CommandRegistrar(self::$container))->register();
        }

        // Aplicação CIF própria: login, dashboard e autorização interna.
        self::$container->router()->register();

        // Bootstrap explícito do primeiro ADMIN_CIF. Essa é a única integração
        // administrativa com wp-admin; depois do primeiro usuário, a gestão de
        // acessos ocorre dentro do próprio Sistema CIF.
        if (function_exists('is_admin') && is_admin()) {
            self::$container->firstAdminBootstrap()->register();
            add_filter('plugin_action_links_' . plugin_basename(SMTT_CIF_GESTAO_FILE), [self::class, 'pluginActionLinks']);

            // Além da ativação, repetimos a checagem no admin de forma idempotente.
            // Isso cobre atualização/substituição do ZIP quando o WordPress mantém o
            // plugin ativo e, portanto, não dispara novamente o activation hook.
            add_action('admin_init', [self::class, 'ensureInstalledAfterUpdate'], 1);
            add_action('admin_notices', [self::class, 'renderInstallationError']);
        }
    }


    /** @param array<int|string,string> $links */
    public static function pluginActionLinks(array $links): array
    {
        $open = '<a href="' . esc_url(home_url('/cif')) . '">Abrir Sistema CIF</a>';
        array_unshift($links, $open);
        return $links;
    }

    public static function activate(): void
    {
        $container = new Container();
        $container->environment()->assertRuntime();
        $container->installer()->ensureInstalled();
        $container->syncScheduler()->ensureScheduled();
        update_option(self::RUNTIME_VERSION_OPTION, SMTT_CIF_GESTAO_VERSION, false);
    }

    public static function deactivate(): void
    {
        \SMTT\CIF\Gestao\Sync\SyncScheduler::clearScheduled();
    }

    public static function ensureRuntimeVersion(): void
    {
        if (self::$container === null) {
            return;
        }
        $installed = (string) get_option(self::RUNTIME_VERSION_OPTION, '');
        if ($installed === SMTT_CIF_GESTAO_VERSION) {
            return;
        }
        self::$container->syncScheduler()->ensureScheduled();
        update_option(self::RUNTIME_VERSION_OPTION, SMTT_CIF_GESTAO_VERSION, false);
    }

    /**
     * A Gestão possui login/rotas CIF próprios. O usuário operacional pode nunca
     * abrir o wp-admin após atualizar o ZIP: aplicar novas migrations uma única
     * vez ao entrar em /cif, sem tocar o banco em páginas WordPress alheias.
     */
    public static function ensureInstalledForCifUpdate(): void
    {
        if (self::$container === null ||
            (string) get_option('smtt_cif_gestao_migrated_version', '') === SMTT_CIF_GESTAO_VERSION) {
            return;
        }
        $requested = (string) (wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        $cifRoot = rtrim((string) (wp_parse_url(home_url('/cif'), PHP_URL_PATH) ?: '/cif'), '/');
        if ($requested !== $cifRoot && !str_starts_with($requested, $cifRoot . '/')) {
            return;
        }
        self::ensureInstalledAfterUpdate();
        if (self::$installationError !== null) {
            // Impede operar uma versão cujo banco ainda não recebeu a nova tabela.
            wp_die('O Sistema CIF não conseguiu aplicar a atualização do banco. Contate a equipe de TI e consulte o log técnico. Nenhum dado anterior foi excluído.');
        }
    }

    public static function ensureInstalledAfterUpdate(): void
    {
        if (self::$container === null) {
            return;
        }

        // Migrations não devem ser varridas em cada admin request.
        $installed = (string) get_option('smtt_cif_gestao_migrated_version', '');
        if ($installed === SMTT_CIF_GESTAO_VERSION) {
            return;
        }

        try {
            self::$container->installer()->ensureInstalled();
            update_option('smtt_cif_gestao_migrated_version', SMTT_CIF_GESTAO_VERSION, false);
            self::$installationError = null;
        } catch (\Throwable $e) {
            self::$installationError = $e->getMessage();
            error_log('[SMTT CIF] Falha ao preparar banco/migrations: ' . $e->getMessage());
        }
    }

    public static function renderInstallationError(): void
    {
        if (self::$installationError === null) {
            return;
        }

        if (function_exists('current_user_can') && !current_user_can('activate_plugins')) {
            return;
        }

        $message = sprintf(
            'SMTT CIF - Gestão: o banco oficial não pôde ser preparado automaticamente. %s',
            self::$installationError
        );

        echo '<div class="notice notice-error"><p><strong>' .
            esc_html($message) .
            '</strong></p></div>';
    }

    public static function container(): Container
    {
        if (self::$container === null) {
            throw new \LogicException('O plugin SMTT CIF ainda não foi inicializado.');
        }

        return self::$container;
    }
}
