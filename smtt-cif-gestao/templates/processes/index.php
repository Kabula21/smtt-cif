<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
/** @var list<array<string,mixed>> $items */
$bodyClass = 'cif-page--list';
$listUrl = home_url('/cif/processos');
$queryBase = [];
foreach (['q','status','tipo','criado','ordenar','direcao'] as $key) {
    $value = (string)($filters[$key] ?? '');
    if ($value !== '') { $queryBase[$key] = $value; }
}
$from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
$to = min($total, $page * $perPage);
$currentSort = (string)($filters['ordenar'] ?? 'criado');
$currentDirection = (string)($filters['direcao'] ?? 'DESC');
$sortHeader = static function (string $label, string $key, string $defaultDirection = 'ASC') use ($listUrl,$queryBase,$currentSort,$currentDirection,$perPage): string {
    $active = $currentSort === $key;
    $next = $active ? ($currentDirection === 'ASC' ? 'DESC' : 'ASC') : $defaultDirection;
    $args = array_merge($queryBase,['ordenar'=>$key,'direcao'=>$next,'pagina'=>1,'por_pagina'=>$perPage]);
    $icon = !$active ? '↕' : ($currentDirection === 'ASC' ? '↑' : '↓');
    return '<a class="cif-sort-link'.($active?' is-active':'').'" href="'.esc_url(add_query_arg($args,$listUrl)).'">'.esc_html($label).'<span aria-hidden="true">'.$icon.'</span></a>';
};
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-page-head cif-page-head--compact">
    <div><p class="cif-eyebrow">Fluxo administrativo</p><h1>Processos de emissão</h1><p>Solicitação, conferência, documentos e aprovação antes da emissão da CIF.</p></div>
    <?php if ($canCreate): ?><a class="cif-button cif-button--primary" href="<?= esc_url(home_url('/cif/processos/novo')) ?>">Novo processo</a><?php endif; ?>
</section>
<?php if ($notice): ?><div class="cif-alert cif-alert--<?= esc_attr($notice['type']) ?>"><?= esc_html($notice['text']) ?></div><?php endif; ?>
<section class="cif-list-screen">
    <form method="get" action="<?= esc_url($listUrl) ?>" class="cif-list-filters">
        <div class="cif-filter-row cif-filter-row--wide">
            <label class="cif-field cif-field--grow"><span>Buscar</span><input type="search" name="q" value="<?= esc_attr((string)$filters['q']) ?>" placeholder="Agente, matrícula ou protocolo"></label>
            <label class="cif-field"><span>Status</span><select name="status"><option value="">Todos</option><?php foreach($statusOptions as $code=>$label): ?><option value="<?= esc_attr($code) ?>" <?= selected($filters['status'],$code,false) ?>><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
            <label class="cif-field"><span>Tipo</span><select name="tipo"><option value="">Todos</option><?php foreach($typeOptions as $code=>$label): ?><option value="<?= esc_attr($code) ?>" <?= selected($filters['tipo'],$code,false) ?>><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
            <label class="cif-field"><span>Criado em</span><input type="date" name="criado" value="<?= esc_attr((string)($filters['criado'] ?? '')) ?>"></label>
            <div class="cif-filter-row__actions"><button class="cif-button cif-button--primary" type="submit">Buscar</button><a class="cif-button cif-button--ghost" data-no-modal href="<?= esc_url($listUrl) ?>">Limpar</a></div>
        </div>
    </form>
    <div class="cif-list-meta"><div><strong>Processos</strong><span><?= esc_html((string)$total) ?> registro(s)</span></div><div class="cif-list-range">Mostrando <?= esc_html((string)$from) ?>–<?= esc_html((string)$to) ?> de <?= esc_html((string)$total) ?></div></div>
    <div class="cif-list-scroll"><table class="cif-table"><thead><tr>
        <th><?= $sortHeader('Agente','nome','ASC') ?></th>
        <th><?= $sortHeader('Tipo','tipo','ASC') ?></th>
        <th><?= $sortHeader('Status','status','ASC') ?></th>
        <th><?= $sortHeader('Protocolo CIF','protocolo','DESC') ?></th>
        <th><?= $sortHeader('Criado em','criado','DESC') ?></th>
        <th class="cif-table__actions">Ações</th>
    </tr></thead><tbody>
    <?php if ($items === []): ?><tr><td colspan="6" class="cif-empty">Nenhum processo encontrado.</td></tr><?php else: foreach($items as $item): ?><tr>
        <td><strong><?= esc_html((string)$item['nome_completo']) ?></strong><br><span class="cif-muted cif-small">Matrícula <?= esc_html((string)$item['matricula']) ?></span></td>
        <td><?= esc_html((string)$item['tipo_label']) ?></td>
        <td><span class="cif-badge cif-process-status--<?= esc_attr(strtolower((string)$item['status_codigo'])) ?>"><?= esc_html((string)$item['status_label']) ?></span></td>
        <td><strong><code><?= esc_html((string)$item['numero_protocolo']) ?></code></strong><br><span class="cif-muted cif-small">Ciclo <?= str_pad((string)($item['ciclo_numero'] ?? 0),2,'0',STR_PAD_LEFT) ?></span><?php if (!empty($item['protocolo_externo'])): ?><br><span class="cif-muted cif-small">Ref. externa: <?= esc_html((string)$item['protocolo_externo']) ?></span><?php endif; ?></td>
        <td><?= esc_html(wp_date('d/m/Y H:i',strtotime((string)$item['criado_em'].' UTC'))) ?></td>
        <td class="cif-table__actions"><a class="cif-link-action" href="<?= esc_url(home_url('/cif/processos/'.rawurlencode((string)$item['uuid']))) ?>">Abrir processo</a></td>
    </tr><?php endforeach; endif; ?></tbody></table></div>
    <?php $itemLabel='processo(s)'; require SMTT_CIF_GESTAO_PATH . 'templates/layout/list-footer.php'; ?>
</section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
