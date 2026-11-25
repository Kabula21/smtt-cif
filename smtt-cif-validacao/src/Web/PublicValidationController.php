<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Web;

use DateTimeImmutable;
use DateTimeZone;
use SMTT\CIF\Validacao\Service\RuntimeModeService;
use SMTT\CIF\Validacao\Service\ValidationService;

final class PublicValidationController
{
    public function __construct(
        private readonly ValidationService $validation,
        private readonly RuntimeModeService $runtimeMode
    ) {
    }

    public function register(): void
    {
        add_action('init', [self::class, 'addRewriteRule']);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'smtt_cif_token';
            return $vars;
        });
        add_action('template_redirect', [$this, 'maybeRender'], 0);
    }

    public static function addRewriteRule(): void
    {
        // Token CIF: 256 bits em Base64URL sem padding = exatamente 43 caracteres.
        add_rewrite_rule('^validar/([A-Za-z0-9_-]{43})/?$', 'index.php?smtt_cif_token=$matches[1]', 'top');
    }

    public function maybeRender(): void
    {
        $token = (string)get_query_var('smtt_cif_token', '');
        if ($token === '') {
            return;
        }
        $this->render($token);
        exit;
    }

    private function render(string $token): void
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';
        $checked = $this->validation->validate($token, $ip);
        $result = $checked['result'];
        $record = $checked['record'];

        if ($result === 'NOT_FOUND') {
            status_header(404);
        } elseif ($result === 'RATE_LIMITED') {
            status_header(429);
            header('Retry-After: 900');
        } else {
            status_header(200);
        }

        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'none'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
        if ($this->runtimeMode->isProduction() && function_exists('is_ssl') && is_ssl()) {
            header('Strict-Transport-Security: max-age=31536000');
        }

        $css = SMTT_CIF_VALIDACAO_URL . 'assets/css/public.css?v=' . rawurlencode(SMTT_CIF_VALIDACAO_VERSION);
        $logo = SMTT_CIF_VALIDACAO_URL . 'assets/img/smtt-logo-color.png?v=' . rawurlencode(SMTT_CIF_VALIDACAO_VERSION);
        ?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
    <title>Validação da Carteira de Identificação Funcional — SMTT Aracaju</title>
    <link rel="stylesheet" href="<?= esc_url($css) ?>">
</head>
<body>
<?php if (!$this->runtimeMode->isProduction()): ?>
    <div class="cif-environment-banner" role="status">AMBIENTE LOCAL / HOMOLOGAÇÃO — consulta de teste, sem validade institucional</div>
<?php endif; ?>
<main class="cif-page" aria-labelledby="cif-title">
    <div class="cif-brand-row" aria-label="SMTT Aracaju — Sistema CIF">
        <img class="cif-brand-logo" src="<?= esc_url($logo) ?>" alt="SMTT Aracaju">
        <span class="cif-brand-divider" aria-hidden="true"></span>
        <span class="cif-system-name"><strong>Sistema CIF</strong><small>Carteira de Identificação Funcional</small></span>
    </div>

    <header class="cif-heading">
        <h1 id="cif-title">Validação da Carteira de Identificação Funcional</h1>
        <p>Consulta pública da situação registrada no Sistema CIF da SMTT Aracaju.</p>
    </header>

    <section class="cif-card" aria-label="Situação da Carteira de Identificação Funcional">
        <?php if (($result === 'VALID' || $result === 'NOT_VALID') && is_array($record)): ?>
            <?= $this->resultCard($record, $result === 'VALID') ?>
        <?php else: ?>
            <?= $this->unavailableCard() ?>
        <?php endif; ?>

        <footer class="cif-note">
            <span class="cif-note-icon" aria-hidden="true">i</span>
            <div>
                <p>A consulta reflete a situação registrada no portal público no momento da última sincronização com o sistema oficial da SMTT.</p>
                <p>Este portal não oferece pesquisa pública por nome, CPF, matrícula ou código da CIF.</p>
            </div>
        </footer>
    </section>
</main>
</body>
</html><?php
    }

    private function unavailableCard(): string
    {
        ob_start();
        ?>
        <div class="cif-alert cif-alert-neutral" role="alert">
            <span class="cif-alert-icon" aria-hidden="true">!</span>
            <div>
                <strong>Não foi possível consultar esta carteira</strong>
                <span>A consulta solicitada não está disponível. Verifique o QR Code original da Carteira de Identificação Funcional.</span>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    /** @param array<string,mixed> $record */
    private function resultCard(array $record, bool $valid): string
    {
        $admission = !empty($record['admission_date']) ? $this->formatDate((string)$record['admission_date']) : '—';
        $issued = $this->formatDate((string)$record['issued_at']);
        $lastSync = $this->formatDateTime((string)$record['last_synced_at']);
        ob_start();
        ?>
        <section class="cif-result <?= $valid ? 'is-valid' : 'is-invalid' ?>" aria-live="polite">
            <div class="cif-result-heading">
                <span class="cif-status-icon" aria-hidden="true"><?= $valid ? '✓' : '!' ?></span>
                <div class="cif-status-copy">
                    <span class="cif-status-label">Situação da CIF</span>
                    <h2><?= $valid ? 'Carteira Funcional Válida' : 'Carteira sem validade' ?></h2>
                    <p><?= $valid ? 'Carteira funcional válida e ativa.' : 'Esta carteira não possui mais validade.' ?></p>
                </div>
            </div>
            <dl class="cif-data">
                <div><dt>Nome</dt><dd><?= esc_html((string)$record['name']) ?></dd></div>
                <div><dt>Matrícula</dt><dd><?= esc_html((string)$record['registration']) ?></dd></div>
                <div><dt>Admissão</dt><dd><?= esc_html($admission) ?></dd></div>
                <div><dt>Código</dt><dd><?= esc_html((string)$record['code']) ?></dd></div>
                <div><dt>Emissão</dt><dd><?= esc_html($issued) ?></dd></div>
                <div><dt>Situação</dt><dd class="cif-public-status"><?= $valid ? 'ATIVA' : 'NÃO VÁLIDA' ?></dd></div>
                <div><dt>Última sincronização</dt><dd><?= esc_html($lastSync) ?></dd></div>
            </dl>
        </section>
        <?php
        return (string)ob_get_clean();
    }

    private function formatDate(string $value): string
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('d/m/Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function formatDateTime(string $value): string
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('d/m/Y H:i') . ' UTC';
        } catch (\Throwable) {
            return $value;
        }
    }
}
