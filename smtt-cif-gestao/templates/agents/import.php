<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
/** @var string|null $error */
/** @var list<array<string,mixed>> $recentBatches */
/** @var bool $xlsxAvailable */
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-page-head">
    <div>
        <p class="cif-eyebrow">Carga inicial e inclusão em lote</p>
        <h1>Importar Agentes de Trânsito</h1>
        <p>O arquivo é validado antes de qualquer cadastro. Duplicidades e inconsistências são exibidas para conferência.</p>
    </div>
    <a class="cif-button cif-button--ghost" href="<?= esc_url(home_url('/cif/agentes')) ?>">Voltar aos agentes</a>
</section>

<?php if ($error !== null): ?>
    <div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<div class="cif-detail-grid">
    <section class="cif-panel">
        <h2>1. Baixe o modelo</h2>
        <p class="cif-muted">Use os nomes de colunas do modelo. Datas devem preferencialmente estar em <strong>AAAA-MM-DD</strong>.</p>
        <div class="cif-inline-actions">
            <a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/agentes/importar/modelo.csv')) ?>">Modelo CSV</a>
            <?php if ($xlsxAvailable): ?>
                <a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/agentes/importar/modelo.xlsx')) ?>">Modelo XLSX</a>
            <?php endif; ?>
        </div>
        <?php if (!$xlsxAvailable): ?>
            <p class="cif-small cif-muted">XLSX não está habilitado neste PHP. O CSV continua disponível e plenamente suportado.</p>
        <?php endif; ?>

        <hr class="cif-divider">

        <h2>2. Envie o arquivo</h2>
        <form class="cif-form" method="post" enctype="multipart/form-data" action="<?= esc_url(home_url('/cif/agentes/importar')) ?>">
            <input type="hidden" name="smtt_cif_nonce" value="<?= esc_attr(smtt_cif_create_nonce('smtt_cif_agent_import_prepare')) ?>">
            <label class="cif-field">
                <span>Arquivo CSV ou XLSX</span>
                <input type="file" name="arquivo" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <small>Máximo de 10 MB e 5.000 linhas de dados. O arquivo original não é armazenado após o processamento.</small>
            </label>
            <div class="cif-alert">
                <strong>Importação segura:</strong> esta etapa somente prepara uma pré-visualização. Nenhum agente é cadastrado até você confirmar o lote.
            </div>
            <div>
                <button class="cif-button cif-button--primary" type="submit">Validar e pré-visualizar</button>
            </div>
        </form>
    </section>

    <aside class="cif-panel">
        <h2>Regras principais</h2>
        <ul class="cif-rule-list">
            <li>Matrícula e CPF devem ser únicos.</li>
            <li>CPF inválido não é armazenado no lote.</li>
            <li>CPF válido fica criptografado durante a pré-visualização.</li>
            <li>Agentes existentes não são sobrescritos.</li>
            <li>Cada linha aprovada usa as mesmas validações do cadastro manual.</li>
            <li>O lote e o resultado de cada linha permanecem auditáveis.</li>
        </ul>
    </aside>
</div>

<section class="cif-section">
    <div class="cif-section__head">
        <div>
            <h2>Importações recentes</h2>
            <p>Histórico dos últimos lotes preparados.</p>
        </div>
    </div>
    <div class="cif-table-wrap">
        <table class="cif-table">
            <thead><tr><th>Arquivo</th><th>Status</th><th>Total</th><th>Importados</th><th>Data</th><th class="cif-table__actions">Ação</th></tr></thead>
            <tbody>
            <?php if ($recentBatches === []): ?>
                <tr><td colspan="6" class="cif-empty">Nenhuma importação registrada.</td></tr>
            <?php else: ?>
                <?php foreach ($recentBatches as $batch): ?>
                    <tr>
                        <td><strong><?= esc_html((string) $batch['nome_arquivo']) ?></strong><br><span class="cif-small cif-muted"><?= esc_html((string) $batch['formato']) ?></span></td>
                        <td><span class="cif-badge"><?= esc_html((string) $batch['status']) ?></span></td>
                        <td><?= esc_html((string) $batch['total_linhas']) ?></td>
                        <td><?= esc_html((string) $batch['linhas_importadas']) ?></td>
                        <td><?= esc_html(mysql2date('d/m/Y H:i', (string) $batch['criado_em'] . ' UTC', true)) ?></td>
                        <td class="cif-table__actions"><a class="cif-link-action" href="<?= esc_url(home_url('/cif/agentes/importacoes/' . rawurlencode((string) $batch['uuid']))) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
