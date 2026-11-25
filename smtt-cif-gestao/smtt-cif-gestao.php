<?php
/**
 * Plugin Name: SMTT CIF - Gestão
 * Description: Sistema interno de gestão da Carteira de Identificação Funcional (CIF) da SMTT.
 * Version: 1.17.16
 * Requires PHP: 8.1
 * Author: SMTT
 * Text Domain: smtt-cif-gestao
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SMTT_CIF_GESTAO_VERSION', '1.17.16');
define('SMTT_CIF_GESTAO_FILE', __FILE__);
define('SMTT_CIF_GESTAO_PATH', __DIR__ . DIRECTORY_SEPARATOR);

require_once __DIR__ . '/src/Bootstrap/Autoloader.php';

\SMTT\CIF\Gestao\Bootstrap\Autoloader::register(
    'SMTT\\CIF\\Gestao\\',
    __DIR__ . '/src'
);


/**
 * Proteção CSRF própria do Sistema CIF. Não depende da sessão de usuário do WordPress.
 */
function smtt_cif_create_nonce(string $action): string
{
    return \SMTT\CIF\Gestao\Bootstrap\Plugin::container()->csrfToken()->token($action);
}

function smtt_cif_verify_nonce(string $nonce, string $action): bool
{
    return \SMTT\CIF\Gestao\Bootstrap\Plugin::container()->csrfToken()->verify($nonce, $action);
}

function smtt_cif_nonce_field(string $action, string $name = '_wpnonce', bool $referer = true, bool $display = true): string
{
    unset($referer);
    $field = \SMTT\CIF\Gestao\Bootstrap\Plugin::container()->csrfToken()->field($action, $name);
    if ($display) {
        echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    return $field;
}

function smtt_cif_nonce_url(string $url, string $action, string $name = '_cifcsrf'): string
{
    return add_query_arg($name, rawurlencode(smtt_cif_create_nonce($action)), $url);
}

register_activation_hook(__FILE__, static function (): void {
    try {
        \SMTT\CIF\Gestao\Bootstrap\Plugin::activate();
    } catch (\Throwable $e) {
        if (function_exists('deactivate_plugins')) {
            deactivate_plugins(plugin_basename(__FILE__), true);
        }

        $message =
            'O SMTT CIF - Gestão não pôde concluir a instalação automática do banco. ' .
            $e->getMessage() .
            ' Nenhum banco CIF existente foi apagado ou recriado.';

        wp_die(
            esc_html($message),
            'SMTT CIF - Gestão',
            ['back_link' => true]
        );
    }
});

register_deactivation_hook(__FILE__, [\SMTT\CIF\Gestao\Bootstrap\Plugin::class, 'deactivate']);

try {
    \SMTT\CIF\Gestao\Bootstrap\Plugin::boot();
} catch (\Throwable $e) {
    error_log('[SMTT CIF] Falha ao inicializar o plugin: ' . $e->getMessage());

    if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
        \WP_CLI::warning('SMTT CIF - Gestão não pôde ser inicializado: ' . $e->getMessage());
    }
}
