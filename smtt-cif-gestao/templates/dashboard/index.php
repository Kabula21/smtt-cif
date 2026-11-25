<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
$bodyClass = 'cif-page--dashboard';
$modules = [];
if ($currentUser->can('cif.agente.visualizar')) {
    $modules[] = ['name' => 'Agentes', 'kicker' => 'Base funcional', 'href' => home_url('/cif/agentes'), 'theme' => 'agents'];
}
if ($currentUser->can('cif.processo.visualizar')) {
    $modules[] = ['name' => 'Processos', 'kicker' => 'Jornada administrativa', 'href' => home_url('/cif/processos'), 'theme' => 'processes'];
}
if ($currentUser->can('cif.emissao.visualizar')) {
    $modules[] = ['name' => 'Emissões', 'kicker' => 'Carteiras e QR Code', 'href' => home_url('/cif/emissoes'), 'theme' => 'emissions'];
}
if ($currentUser->isSystemAdministrator()) {
    $modules[] = ['name' => 'Usuários', 'kicker' => 'Perfis e permissões', 'href' => home_url('/cif/seguranca/usuarios'), 'theme' => 'users'];
    $modules[] = ['name' => 'Sincronização', 'kicker' => 'Fila e integrações', 'href' => home_url('/cif/sincronizacao'), 'theme' => 'sync'];
    $modules[] = ['name' => 'Configurações', 'kicker' => 'Preparação da emissão', 'href' => home_url('/cif/emissoes/preparacao'), 'theme' => 'settings'];
}
$orbitCount = count($modules);
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-dashboard-stage">
    <div class="cif-dashboard-stage__hero">
        <div class="cif-dashboard-stage__mark">
            <div class="cif-dashboard-stage__orbital" aria-hidden="true">
                <div class="cif-dashboard-stage__core">
                    <img class="cif-dashboard-stage__icon" src="<?= esc_url($assetBaseUrl . 'img/cif-card-icon.png') ?>" alt="Carteira funcional verificada">
                </div>
                <?php foreach ($modules as $index => $module):
                    $angle = $orbitCount > 0 ? (-90 + ((360 / $orbitCount) * $index)) : 0;
                    $style = sprintf('--orbit-angle:%sdeg;', (string) $angle);
                ?>
                   
                <?php endforeach; ?>
            </div>

            <div class="cif-dashboard-stage__title">
                <span class="cif-dashboard-stage__eyebrow">SMTT Aracaju</span>
                <h1>Sistema CIF</h1>
                <p>Gestão integrada da Carteira de Identificação Funcional dos Agentes de Trânsito, com fluxos contínuos de cadastro, biometria, processos, emissões e histórico completo do ciclo de vida de cada CIF.</p>
                <div class="cif-dashboard-stage__summary">
                    <span>Fluxo operacional integrado</span>
                    <span>Permissões por perfil CIF</span>
                    <span>Criado pela Coordenadoria de Processamento de Dados</span>
                </div>
            </div>
        </div>
    </div>

    <p class="cif-dashboard-stage__hint">O diagrama apresenta os módulos disponíveis ao perfil atual; utilize o menu lateral para acessar cada área do Sistema CIF.</p>
</section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
