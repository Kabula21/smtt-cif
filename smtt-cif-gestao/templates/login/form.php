<?php
/** @var string|null $error */
/** @var string|null $message */
/** @var string $username */
/** @var string $assetBaseUrl */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="theme-color" content="#f4f8fa">
    <title>Entrar — SMTT CIF</title>
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

        <section class="cif-login-card" aria-labelledby="cif-login-title">
            <p class="cif-eyebrow">Acesso institucional</p>
            <h2 id="cif-login-title">Entrar no sistema</h2>
            <p>Use o login e a senha cadastrados no próprio Sistema CIF.</p>

            <?php if ($message !== null): ?><div class="cif-alert cif-alert--success" role="status"><?= esc_html($message) ?></div><?php endif; ?>
            <?php if ($error !== null): ?><div class="cif-alert cif-alert--error" role="alert"><?= esc_html($error) ?></div><?php endif; ?>

            <form method="post" action="<?= esc_url(home_url('/cif/login')) ?>" class="cif-form">
                <?php smtt_cif_nonce_field('smtt_cif_login', 'smtt_cif_nonce'); ?>
                <label class="cif-field"><span>Usuário</span><input type="text" name="user_login" value="<?= esc_attr($username) ?>" autocomplete="username" required autofocus></label>
                <label class="cif-field"><span>Senha</span><input type="password" name="user_password" autocomplete="current-password" required></label>
                <label class="cif-checkbox"><input type="checkbox" name="remember" value="1"><span><strong>Manter sessão</strong><small>Use apenas em equipamento institucional confiável.</small></span></label>
                <button class="cif-button cif-button--primary cif-button--block" type="submit">Entrar</button>
            </form>
        </section>
    </div>
</div>
</body>
</html>
