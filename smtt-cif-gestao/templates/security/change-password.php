<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
/** @var string|null $error */
/** @var string $assetBaseUrl */
$requiredChange = $currentUser->mustChangePassword;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="theme-color" content="#f4f8fa">
    <title>Alterar senha — SMTT CIF</title>
    <link rel="icon" type="image/png" href="<?= esc_url($assetBaseUrl . 'img/cif-card-icon-96.png?v=' . rawurlencode(SMTT_CIF_GESTAO_VERSION)) ?>">
    <link rel="stylesheet" href="<?= esc_url($assetBaseUrl . 'css/app.css?v=' . rawurlencode(SMTT_CIF_GESTAO_VERSION)) ?>">
</head>
<body class="cif-login-page">
<div class="cif-login-shell">
    <div class="cif-login-layout">
        <section class="cif-login-aside" aria-label="Identidade do sistema">
            <div class="cif-login-aside__content">
                <img class="cif-login-icon cif-login-icon--lead" src="<?= esc_url($assetBaseUrl . 'img/cif-card-icon.png') ?>" alt="Carteira funcional verificada">
                <p class="cif-login-kicker">Sistema de CArteira de Identificação FUncional</p>
                <h1>Sistema CIF</h1>
                <p class="cif-login-lead">Gestão integrada da Carteira de Identificação Funcional dos Agentes de Trânsito da SMTT Aracaju.</p>
                <ul class="cif-login-summary">
                    <li>Cadastro funcional, biometria, processos administrativos e emissões.</li>
                    <li>QR Code exclusivo, impressão, entrega e histórico completo.</li>
                    <li>Auditoria, controle de acessos, perfis operacionais e sincronização segura.</li>
                    <li>Fluxo institucional rastreável, com governança e histórico por agente.</li>
                </ul>
                <div class="cif-login-authorship">
                    <strong>Desenvolvido pela Coordenadoria de Processamento de Dados</strong>
                    <span>Superintendência Municipal de Transportes e Trânsito — Aracaju</span>
                </div>
            </div>
            <div class="cif-login-logo-wrap">
                <span>Institucional</span>
                <img class="cif-login-logo" src="<?= esc_url($assetBaseUrl . 'img/smtt-logo-color.png') ?>" alt="SMTT Aracaju">
            </div>
        </section>

        <section class="cif-login-card" aria-labelledby="cif-password-title">
            <p class="cif-eyebrow">Segurança da conta</p>
            <h2 id="cif-password-title">Alterar senha</h2>
            <p><?= esc_html($requiredChange
                ? 'A senha inicial precisa ser substituída antes do primeiro acesso ao Sistema CIF.'
                : 'Informe sua senha atual e defina uma nova senha de acesso ao Sistema CIF.') ?></p>

            <?php if ($error !== null): ?><div class="cif-alert cif-alert--error" role="alert"><?= esc_html($error) ?></div><?php endif; ?>

            <div class="cif-password-account" aria-label="Conta em alteração">
                <span>Conta CIF</span>
                <strong><?= esc_html($currentUser->name) ?></strong>
                <small><?= esc_html($currentUser->login) ?><?= ($currentUser->department ?? '') !== '' ? ' · ' . esc_html((string)$currentUser->department) : '' ?></small>
            </div>

            <form method="post" action="<?= esc_url(home_url('/cif/minha-senha')) ?>" class="cif-form">
                <?php \smtt_cif_nonce_field('smtt_cif_change_own_password','smtt_cif_nonce'); ?>
                <label class="cif-field"><span>Senha atual</span><input type="password" name="current_password" autocomplete="current-password" required autofocus></label>
                <label class="cif-field"><span>Nova senha</span><input type="password" name="new_password" minlength="10" autocomplete="new-password" required><small>Mínimo de 10 caracteres.</small></label>
                <label class="cif-field"><span>Confirmar nova senha</span><input type="password" name="confirm_password" minlength="10" autocomplete="new-password" required></label>
                <button class="cif-button cif-button--primary cif-button--block" type="submit">Alterar senha</button>
            </form>

            <p class="cif-login-footnote">Após a alteração, sua sessão será encerrada e você deverá entrar novamente com a nova senha.</p>
            <?php if (!$requiredChange): ?><p class="cif-login-back"><a href="<?= esc_url(home_url('/cif')) ?>">Cancelar e voltar ao Sistema CIF</a></p><?php endif; ?>
        </section>
    </div>
</div>
</body>
</html>
