<?php
/**
 * Plugin Name: SMTT CIF - Validação
 * Description: Portal público de validação da Carteira de Identificação Funcional (CIF) da SMTT.
 * Version: 0.7.0
 * Requires PHP: 8.1
 * Author: SMTT
 * Text Domain: smtt-cif-validacao
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SMTT_CIF_VALIDACAO_VERSION', '0.7.0');
define('SMTT_CIF_VALIDACAO_FILE', __FILE__);
define('SMTT_CIF_VALIDACAO_PATH', __DIR__ . DIRECTORY_SEPARATOR);
define('SMTT_CIF_VALIDACAO_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/src/Bootstrap/Autoloader.php';

\SMTT\CIF\Validacao\Bootstrap\Autoloader::register(
    'SMTT\\CIF\\Validacao\\',
    __DIR__ . '/src'
);

register_activation_hook(__FILE__, static function (): void {
    try {
        \SMTT\CIF\Validacao\Bootstrap\Plugin::activate();
    } catch (\Throwable $e) {
        if (function_exists('deactivate_plugins')) {
            deactivate_plugins(plugin_basename(__FILE__), true);
        }
        wp_die(
            esc_html('O SMTT CIF - Validação não pôde concluir a instalação: ' . $e->getMessage()),
            'SMTT CIF - Validação',
            ['back_link' => true]
        );
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules(false);
    }
});

try {
    \SMTT\CIF\Validacao\Bootstrap\Plugin::boot();
} catch (\Throwable $e) {
    error_log('[SMTT CIF Validação] Falha ao inicializar: ' . $e->getMessage());
}
