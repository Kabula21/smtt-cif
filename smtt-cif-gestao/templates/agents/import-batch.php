<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
/** @var array<string,mixed> $batch */
/** @var string|null $error */
/** @var array{type:string,text:string}|null $notice */
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
$items = is_array($batch['items'] ?? null) ? $batch['items'] : [];
$isPreview = (string) $batch['status'] === 'PREVIEW';
$isProcessing = (string) $batch['status'] === 'PROCESSING';
?>
<section class="cif-page-head">
    <div>
        <p class="cif-eyebrow">Importação de agentes</p>
        <h1><?= esc_html((string) $batch['nome_arquivo']) ?></h1>
        <p>Lote <?= esc_html(substr((string) $batch['uuid'], 0, 8)) ?> · <?= esc_html((string) $batch['formato']) ?> · criado em <?= esc_html(mysql2date('d/m/Y H:i', (string) $batch['criado_em'] . ' UTC', true)) ?></p>
    </div>
    <a class="cif-button cif-button--ghost" href="<?= esc_url(home_url('/cif/agentes/importar')) ?>">Voltar às importações</a>
</section>

<?php if ($notice !== null): ?><div class="cif-alert cif-alert--<?= esc_attr($notice['type']) ?>"><?= esc_html($notice['text']) ?></div><?php endif; ?>
<?php if ($error !== null): ?><div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div><?php endif; ?>

<div class="cif-stats">
    <article class="cif-stat"><span>Total de linhas</span><strong><?= esc_html((string) $batch['total_linhas']) ?></strong></article>
    <article class="cif-stat"><span>Prontas</span><strong><?= esc_html((string) $batch['linhas_prontas']) ?></strong></article>
    <article class="cif-stat"><span>Duplicadas/conflitos</span><strong><?= esc_html((string) $batch['linhas_duplicadas']) ?></strong></article>
    <article class="cif-stat"><span>Com erro</span><strong><?= esc_html((string) $batch['linhas_com_erro']) ?></strong></article>
</div>

<section class="cif-panel cif-section">
    <div class="cif-section__head">
        <div>
            <h2>Status do lote: <?= esc_html((string) $batch['status']) ?></h2>
            <p><?= esc_html((string) $batch['linhas_importadas']) ?> agente(s) efetivamente importado(s).</p>
        </div>
        <?php if ($isPreview || $isProcessing): ?>
            <form method="post" action="<?= esc_url(home_url('/cif/agentes/importacoes/' . rawurlencode((string) $batch['uuid']))) ?>">
                <input type="hidden" name="smtt_cif_nonce" value="<?= esc_attr(smtt_cif_create_nonce('smtt_cif_agent_import_confirm_' . (string) $batch['uuid'])) ?>">
                <button class="cif-button cif-button--primary" type="submit" <?= (int) $batch['linhas_prontas'] === 0 ? 'disabled' : '' ?>>
                    <?= $isProcessing ? 'Retomar processamento' : 'Confirmar importação' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($isPreview): ?>
        <div class="cif-alert"><strong>Nada foi cadastrado ainda.</strong> Apenas linhas marcadas como <code>READY</code> serão processadas ao confirmar.</div>
    <?php endif; ?>
</section>

<section class="cif-section">
    <div class="cif-table-wrap">
        <table class="cif-table cif-table--import">
            <thead><tr><th>Linha</th><th>Agente</th><th>Matrícula</th><th>Situação</th><th>Resultado</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): $data = is_array($item['dados'] ?? null) ? $item['dados'] : []; ?>
                <tr>
                    <td><?= esc_html((string) $item['numero_linha']) ?></td>
                    <td><?= esc_html((string) ($data['nome_completo'] ?? '—')) ?></td>
                    <td><?= esc_html((string) ($item['matricula'] ?? '—')) ?></td>
                    <td><?= esc_html((string) ($data['situacao_funcional'] ?? '—')) ?></td>
                    <td>
                        <span class="cif-badge cif-badge--status-<?= esc_attr(strtolower((string) $item['status'])) ?>"><?= esc_html((string) $item['status']) ?></span>
                        <?php if (!empty($item['mensagem_erro'])): ?><div class="cif-small cif-import-error"><?= esc_html((string) $item['mensagem_erro']) ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
