<?php
$bodyClass = 'cif-page--list';
$fmt = static fn($value): string => is_string($value) && $value !== '' ? wp_date('d/m/Y H:i:s', strtotime($value . ' UTC')) : '—';
$queryBase = ['q'=>$filters['q'],'operacao'=>$filters['operacao'],'entidade'=>$filters['entidade'],'de'=>$filters['de'],'ate'=>$filters['ate'],'por_pagina'=>$perPage];
$from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
$to = min($total, $page * $perPage);
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-page-head cif-page-head--compact">
    <div><p class="cif-eyebrow">Rastreabilidade</p><h1>Auditoria</h1><p>Registro append-only das operações do Sistema CIF, com ator, origem, conteúdo anterior/posterior e integridade criptográfica dos novos registros.</p></div>
</section>

<div class="cif-alert <?= $integrity['valid'] ? 'cif-alert--success' : 'cif-alert--error' ?>">
    <strong>Integridade da cadeia:</strong> <?= esc_html($integrity['valid'] ? 'íntegra' : 'atenção necessária') ?>. <?= esc_html($integrity['message']) ?>
    <?php if ((int)$integrity['legacy_count'] > 0): ?><br><span class="cif-small">Há <?= esc_html((string)$integrity['legacy_count']) ?> registro(s) legado(s) anteriores à ativação do encadeamento; eles permanecem preservados, mas não possuem hash retroativo.</span><?php endif; ?>
    <?php if ($integrity['first_invalid_id'] !== null): ?><br><strong>Primeiro registro inconsistente:</strong> #<?= esc_html((string)$integrity['first_invalid_id']) ?><?php endif; ?>
</div>

<section class="cif-list-screen">
    <form method="get" action="<?= esc_url(home_url('/cif/auditoria')) ?>" class="cif-list-filters">
        <div class="cif-filter-grid">
            <label class="cif-field cif-field--grow"><span>Buscar</span><input type="search" name="q" value="<?= esc_attr($filters['q']) ?>" placeholder="Operação, usuário, UUID ou entidade"></label>
            <label class="cif-field"><span>Operação</span><select name="operacao"><option value="">Todas</option><?php foreach($operations as $operation): ?><option value="<?= esc_attr($operation) ?>" <?= selected($filters['operacao'],$operation,false) ?>><?= esc_html($operation) ?></option><?php endforeach; ?></select></label>
            <label class="cif-field"><span>Entidade</span><select name="entidade"><option value="">Todas</option><?php foreach($entityTypes as $entity): ?><option value="<?= esc_attr($entity) ?>" <?= selected($filters['entidade'],$entity,false) ?>><?= esc_html($entity) ?></option><?php endforeach; ?></select></label>
            <label class="cif-field"><span>De</span><input type="date" name="de" value="<?= esc_attr($filters['de']) ?>"></label>
            <label class="cif-field"><span>Até</span><input type="date" name="ate" value="<?= esc_attr($filters['ate']) ?>"></label>
            <label class="cif-field"><span>Por página</span><select name="por_pagina"><?php foreach([25,50,100] as $size): ?><option value="<?= $size ?>" <?= selected($perPage,$size,false) ?>><?= $size ?></option><?php endforeach; ?></select></label>
            <div class="cif-filter-actions"><button class="cif-button cif-button--primary" type="submit">Aplicar</button><a class="cif-button cif-button--ghost" href="<?= esc_url(home_url('/cif/auditoria')) ?>">Limpar</a></div>
        </div>
    </form>
    <div class="cif-list-meta"><div><strong>Eventos auditados</strong><span><?= esc_html((string)$total) ?> registro(s)</span></div><div class="cif-list-range">Mostrando <?= esc_html((string)$from) ?>–<?= esc_html((string)$to) ?> de <?= esc_html((string)$total) ?></div></div>
    <div class="cif-list-scroll"><table class="cif-table"><thead><tr><th>Data/hora</th><th>Usuário</th><th>Operação</th><th>Entidade</th><th>Origem</th><th>Integridade</th><th class="cif-table__actions">Detalhes</th></tr></thead><tbody>
    <?php if($items===[]): ?><tr><td colspan="7" class="cif-empty">Nenhum registro de auditoria localizado.</td></tr><?php else: foreach($items as $row): $modalId='audit-'.(int)$row['id']; ?><tr>
        <td><?= esc_html($fmt((string)$row['ocorrido_em'])) ?></td>
        <td><strong><?= esc_html((string)($row['actor_nome'] ?: 'Sistema')) ?></strong><br><span class="cif-small cif-muted"><?= esc_html((string)($row['actor_login'] ?: '—')) ?></span></td>
        <td><code><?= esc_html((string)$row['operacao']) ?></code></td>
        <td><?= esc_html((string)$row['entidade_tipo']) ?><br><span class="cif-small cif-muted"><?= esc_html((string)($row['entidade_uuid'] ?: ($row['entidade_id'] ?: '—'))) ?></span></td>
        <td><?= esc_html((string)$row['origem']) ?><br><span class="cif-small cif-muted"><?= esc_html((string)($row['ip_origem_texto'] ?: '—')) ?></span></td>
        <td><span class="cif-badge <?= is_string($row['record_hash']) && $row['record_hash'] !== '' ? 'cif-badge--success' : '' ?>"><?= is_string($row['record_hash']) && $row['record_hash'] !== '' ? 'Encadeado' : 'Legado' ?></span></td>
        <td class="cif-table__actions"><button type="button" class="cif-button cif-button--small cif-button--secondary" data-modal-open="<?= esc_attr($modalId) ?>">Ver</button></td>
    </tr><?php endforeach; endif; ?></tbody></table></div>
    <div class="cif-list-footer"><span>Página <?= esc_html((string)$page) ?> de <?= esc_html((string)$pages) ?></span><?php if($pages>1): ?><nav class="cif-pagination"><?php for($i=1;$i<=$pages;$i++): $args=$queryBase; $args['pagina']=$i; ?><a class="<?= $i===$page?'is-current':'' ?>" href="<?= esc_url(add_query_arg(array_filter($args, static fn($v)=>$v!==''),home_url('/cif/auditoria'))) ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?></div>
</section>

<?php foreach($items as $row): $modalId='audit-'.(int)$row['id']; ?>
<div class="cif-modal" id="<?= esc_attr($modalId) ?>" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="<?= esc_attr($modalId) ?>-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog cif-modal__dialog--wide">
        <div class="cif-modal__head">
            <div><span class="cif-modal__eyebrow">Auditoria #<?= (int)$row['id'] ?></span><h2 id="<?= esc_attr($modalId) ?>-title"><?= esc_html((string)$row['operacao']) ?></h2><p><?= esc_html($fmt((string)$row['ocorrido_em'])) ?> · Request <?= esc_html((string)($row['request_id'] ?: '—')) ?></p></div>
            <button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button>
        </div>
        <div class="cif-modal__body">
            <dl class="cif-definition-list">
                <div><dt>Ator</dt><dd><?= esc_html((string)($row['actor_nome'] ?: 'Sistema')) ?> · <?= esc_html((string)($row['actor_login'] ?: '—')) ?></dd></div>
                <div><dt>Entidade</dt><dd><?= esc_html((string)$row['entidade_tipo']) ?> · <?= esc_html((string)($row['entidade_uuid'] ?: ($row['entidade_id'] ?: '—'))) ?></dd></div>
                <div><dt>Justificativa</dt><dd><?= esc_html((string)($row['justificativa'] ?: '—')) ?></dd></div>
                <div><dt>Hash anterior</dt><dd><code><?= $row['previous_hash'] ? esc_html(bin2hex((string)$row['previous_hash'])) : 'legado' ?></code></dd></div>
                <div><dt>Hash do registro</dt><dd><code><?= $row['record_hash'] ? esc_html(bin2hex((string)$row['record_hash'])) : 'legado' ?></code></dd></div>
            </dl>
            <h3>Valor anterior</h3><pre class="cif-code-block"><?= esc_html((string)($row['valor_anterior'] ?: '—')) ?></pre>
            <h3>Valor posterior</h3><pre class="cif-code-block"><?= esc_html((string)($row['valor_posterior'] ?: '—')) ?></pre>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
