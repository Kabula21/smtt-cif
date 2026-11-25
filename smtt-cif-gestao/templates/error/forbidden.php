<?php
/** @var string $message */
/** @var \SMTT\CIF\Gestao\Security\CifUser|null $currentUser */
$activeNav = '';
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-error-page">
    <span class="cif-error-code">403</span>
    <h1>Acesso negado</h1>
    <p><?= esc_html($message) ?></p>
    <a class="cif-button cif-button--primary" href="<?= esc_url($currentUser !== null ? home_url('/cif') : home_url('/cif/login')) ?>">
        <?= $currentUser !== null ? 'Voltar ao painel' : 'Ir para o login' ?>
    </a>
</section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
