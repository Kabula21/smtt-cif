<?php
/** @var string $title */
/** @var string $assetBaseUrl */
/** @var string $cifBaseUrl */
/** @var \SMTT\CIF\Gestao\Security\CifUser|null $currentUser */
/** @var string|null $activeNav */
$activeNav = $activeNav ?? '';
$bodyClass = trim((string)($bodyClass ?? ''));
$embedded = isset($_GET['embedded']) && (string) $_GET['embedded'] === '1';
$userInitial = '';
$isSystemAdmin = false;
if (($currentUser ?? null) !== null) {
    $sourceName = $currentUser->name !== '' ? $currentUser->name : $currentUser->login;
    $userInitial = strtoupper(function_exists('mb_substr') ? mb_substr($sourceName, 0, 1, 'UTF-8') : substr($sourceName, 0, 1));
    $isSystemAdmin = $currentUser->isSystemAdministrator();
}
$svg = static function (string $name): string {
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/></svg>',
        'agents' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0v1H5v-1Z"/></svg>',
        'processes' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h8l4 4v16H6V2Zm8 1.8V7h3.2L14 3.8ZM9 11v2h6v-2H9Zm0 4v2h6v-2H9Z"/></svg>',
        'emissions' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v14H3V5Zm2 2v10h14V7H5Zm2 2h5v6H7V9Zm7 0h3v2h-3V9Zm0 4h3v2h-3v-2Z"/></svg>',
        'sync' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.1 7H4l4-4 4 4H9.1a5 5 0 0 1 8.6 3H15.6A3 3 0 0 0 10 8.5 3 3 0 0 0 9.1 7ZM16 21l-4-4h2.9a5 5 0 0 0-8.6-3H4.2A7 7 0 0 1 16.9 17H20l-4 4Z"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7-2a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM2 21a7 7 0 0 1 14 0H2Zm13.5-8.8A6.9 6.9 0 0 1 19 18h3a5 5 0 0 0-6.5-4.8Z"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 3h16v18H4V3Zm2 2v14h12V5H6Zm2 10h2v2H8v-2Zm0-4h2v2H8v-2Zm4 4h4v2h-4v-2Zm0-4h4v2h-4v-2Zm-4-4h8v2H8V7Z"/></svg>',
        'audit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 20 5v6c0 5.2-3.4 9.7-8 11-4.6-1.3-8-5.8-8-11V5l8-3Zm0 2.1L6 6.3V11c0 4.1 2.5 7.7 6 8.9 3.5-1.2 6-4.8 6-8.9V6.3l-6-2.2Zm-1 4.4h2v4.2l3 1.8-1 1.7-4-2.4V8.5Z"/></svg>',
        'diagnostics' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 2h2v3h-2V2Zm0 17h2v3h-2v-3ZM2 11h3v2H2v-2Zm17 0h3v2h-3v-2ZM5.6 4.2l2.1 2.1-1.4 1.4-2.1-2.1 1.4-1.4Zm12.1 12.1 2.1 2.1-1.4 1.4-2.1-2.1 1.4-1.4Zm.7-12.1 1.4 1.4-2.1 2.1-1.4-1.4 2.1-2.1ZM6.3 16.3l1.4 1.4-2.1 2.1-1.4-1.4 2.1-2.1ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Z"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m19.4 13 .1-1-.1-1 2-1.5-2-3.4-2.4 1a8 8 0 0 0-1.7-1L15 3.5h-4L10.6 6a8 8 0 0 0-1.7 1L6.5 6 4.5 9.5l2 1.5-.1 1 .1 1-2 1.5 2 3.4 2.4-1a8 8 0 0 0 1.7 1l.4 2.6h4l.4-2.6a8 8 0 0 0 1.7-1l2.4 1 2-3.4-2.1-1.5ZM13 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 3H4v18h6v-2H6V5h4V3Zm5 4-1.4 1.4 2.6 2.6H9v2h7.2l-2.6 2.6L15 17l5-5-5-5Z"/></svg>',
        'menu' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v2H3V6Zm0 5h18v2H3v-2Zm0 5h18v2H3v-2Z"/></svg>',
        'collapse' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.6 6-1.4-1.4L5.8 12l7.4 7.4 1.4-1.4L8.6 12l6-6Zm4 0-1.4-1.4L9.8 12l7.4 7.4 1.4-1.4-6-6 6-6Z"/></svg>',
    ];
    return $icons[$name] ?? '';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="theme-color" content="#0d6f8e">
    <title><?= esc_html($title) ?> — SMTT CIF</title>
    <link rel="icon" type="image/png" href="<?= esc_url($assetBaseUrl . 'img/cif-card-icon-96.png?v=' . rawurlencode(SMTT_CIF_GESTAO_VERSION)) ?>">
    <link rel="stylesheet" href="<?= esc_url($assetBaseUrl . 'css/app.css?v=' . rawurlencode(SMTT_CIF_GESTAO_VERSION)) ?>">
    <script defer src="<?= esc_url($assetBaseUrl . 'js/app.js?v=' . rawurlencode(SMTT_CIF_GESTAO_VERSION)) ?>"></script>
</head>
<body class="<?= $currentUser !== null ? 'cif-app' : 'cif-login-page' ?><?= $embedded ? ' cif-embedded' : '' ?><?= $bodyClass !== '' ? ' ' . esc_attr($bodyClass) : '' ?>">
<?php if ($currentUser !== null && !$embedded): ?>
<div class="cif-app-shell" data-cif-shell>
    <aside class="cif-sidebar" id="cif-sidebar" aria-label="Navegação principal">
        <div class="cif-sidebar__brand">            
            <button class="cif-sidebar__collapse" type="button" data-cif-sidebar-collapse aria-label="Recolher menu lateral" aria-pressed="false" title="Recolher menu lateral"><?= $svg('collapse') ?></button>
            <div class="cif-sidebar__product"><span><strong>Sistema CIF</strong><small>Carteira de Identificação Funcional</small></span></div>
        </div>

        <nav class="cif-sidebar__nav">
            <div class="cif-nav-group">
                <span class="cif-nav-group__label">Visão geral</span>
                <a class="cif-sidebar-link <?= $activeNav === 'dashboard' ? 'is-active' : '' ?>" href="<?= esc_url($cifBaseUrl) ?>" title="Painel"><span class="cif-sidebar-link__icon"><?= $svg('dashboard') ?></span><span class="cif-sidebar-link__label">Painel</span></a>
            </div>

            <div class="cif-nav-group">
                <span class="cif-nav-group__label">Operação CIF</span>
                <?php if ($currentUser->can('cif.agente.visualizar')): ?><a class="cif-sidebar-link <?= in_array($activeNav, ['agents','biometry'], true) ? 'is-active' : '' ?>" href="<?= esc_url(home_url('/cif/agentes')) ?>" title="Agentes"><span class="cif-sidebar-link__icon"><?= $svg('agents') ?></span><span class="cif-sidebar-link__label">Agentes</span></a><?php endif; ?>
                <?php if ($currentUser->can('cif.processo.visualizar')): ?><a class="cif-sidebar-link <?= $activeNav === 'processes' ? 'is-active' : '' ?>" href="<?= esc_url(home_url('/cif/processos')) ?>" title="Processos"><span class="cif-sidebar-link__icon"><?= $svg('processes') ?></span><span class="cif-sidebar-link__label">Processos</span></a><?php endif; ?>
                <?php if ($currentUser->can('cif.emissao.visualizar')): ?><a class="cif-sidebar-link <?= $activeNav === 'emissions' ? 'is-active' : '' ?>" href="<?= esc_url(home_url('/cif/emissoes')) ?>" title="Emissões"><span class="cif-sidebar-link__icon"><?= $svg('emissions') ?></span><span class="cif-sidebar-link__label">Emissões</span></a><?php endif; ?>
            </div>

            <?php if ($currentUser->can('cif.relatorio.visualizar')): ?>
            <div class="cif-nav-group">
                <span class="cif-nav-group__label">Consultas</span>
                <a class="cif-sidebar-link <?= $activeNav === 'reports' ? 'is-active' : '' ?>" href="<?= esc_url(home_url('/cif/relatorios')) ?>" title="Relatórios"><span class="cif-sidebar-link__icon"><?= $svg('reports') ?></span><span class="cif-sidebar-link__label">Relatórios</span></a>
            </div>
            <?php endif; ?>

            <?php if ($isSystemAdmin): ?>
            <div class="cif-nav-group cif-nav-group--admin">
                <span class="cif-nav-group__label">Administração</span>
                <a class="cif-sidebar-link <?= $activeNav === 'sync' ? 'is-active' : '' ?>" href="<?= esc_url(home_url('/cif/sincronizacao')) ?>" title="Sincronização"><span class="cif-sidebar-link__icon"><?= $svg('sync') ?></span><span class="cif-sidebar-link__label">Sincronização</span></a>
                <a class="cif-sidebar-link <?= $activeNav === 'security-users' ? 'is-active' : '' ?>" href="<?= esc_url(home_url('/cif/seguranca/usuarios')) ?>" title="Usuários e acessos"><span class="cif-sidebar-link__icon"><?= $svg('users') ?></span><span class="cif-sidebar-link__label">Usuários e acessos</span></a>
                <?php if ($currentUser->can('cif.auditoria.visualizar')): ?><a class="cif-sidebar-link <?= $activeNav === 'audit' ? 'is-active' : '' ?>" href="<?= esc_url(home_url('/cif/auditoria')) ?>" title="Auditoria"><span class="cif-sidebar-link__icon"><?= $svg('audit') ?></span><span class="cif-sidebar-link__label">Auditoria</span></a><?php endif; ?>
                <a class="cif-sidebar-link <?= $activeNav === 'diagnostics' ? 'is-active' : '' ?>" href="<?= esc_url(home_url('/cif/diagnostico')) ?>" title="Diagnóstico"><span class="cif-sidebar-link__icon"><?= $svg('diagnostics') ?></span><span class="cif-sidebar-link__label">Diagnóstico</span></a>
                <a class="cif-sidebar-link <?= $activeNav === 'settings' ? 'is-active' : '' ?>" href="<?= esc_url(home_url('/cif/emissoes/preparacao')) ?>" title="Configurações"><span class="cif-sidebar-link__icon"><?= $svg('settings') ?></span><span class="cif-sidebar-link__label">Configurações</span></a>
            </div>
            <?php endif; ?>
        </nav>
         <a href="<?= esc_url($cifBaseUrl) ?>" class="cif-sidebar__brand-link" aria-label="Sistema CIF — painel">
                <span class="cif-sidebar__logo-plate"><img class="cif-sidebar__logo" src="<?= esc_url($assetBaseUrl . 'img/smtt-logo.png') ?>" alt="SMTT Aracaju"></span>
            </a>
    </aside>

    <div class="cif-sidebar-overlay" data-cif-sidebar-overlay></div>

    <div class="cif-workspace">
        <header class="cif-topbar">
            <button class="cif-icon-button cif-mobile-menu" type="button" data-cif-sidebar-toggle aria-label="Abrir menu" aria-controls="cif-sidebar" aria-expanded="false"><?= $svg('menu') ?></button>
            <div class="cif-topbar__context"><span>Sistema de CArteira de Identificação FUncional</span><strong><?= esc_html($title) ?></strong></div>
            <div class="cif-topbar__user">
                <span class="cif-topbar__avatar"><?= esc_html($userInitial) ?></span>
                <span class="cif-topbar__identity"><strong><?= esc_html($currentUser->name) ?></strong><small><?= esc_html(trim(((string)($currentUser->department ?? '')) . (($currentUser->department ?? '') !== '' ? ' · ' : '') . implode(' · ', $currentUser->profiles))) ?></small></span>
                <span class="cif-status-dot" title="Sessão ativa"></span>
                <a class="cif-topbar__logout" href="<?= esc_url(smtt_cif_nonce_url(home_url('/cif/logout'), 'smtt_cif_logout')) ?>" title="Sair do Sistema CIF"><span class="cif-topbar__logout-icon"><?= $svg('logout') ?></span><span>Sair</span></a>
            </div>
        </header>
        <main class="cif-main">
            <div class="cif-content">
<?php elseif ($currentUser !== null && $embedded): ?>
<main class="cif-main cif-main--embedded"><div class="cif-content cif-content--embedded">
<?php else: ?>
<main class="cif-main cif-main--anonymous"><div class="cif-content cif-content--anonymous">
<?php endif; ?>
