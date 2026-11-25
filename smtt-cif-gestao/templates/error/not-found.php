<?php
/** @var string $message */
/** @var \SMTT\CIF\Gestao\Security\CifUser|null $currentUser */
$activeNav = '';
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-error-page">
    <span class="cif-error-code">404</span>
    <h1>Página não encontrada</h1>
    <p><?= esc_html($message) ?></p>
    <a class="cif-button cif-button--primary" href="<?= esc_url(home_url('/cif')) ?>">Voltar ao Sistema CIF</a>
</section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
