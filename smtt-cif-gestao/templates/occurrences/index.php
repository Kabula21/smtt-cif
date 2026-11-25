<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
$bodyClass='cif-page--list';
$fmt=static fn($value): string => is_string($value)&&$value!=='' ? wp_date('d/m/Y H:i',strtotime($value.' UTC')) : '—';
$listUrl=home_url('/cif/ocorrencias');
$queryBase=[];
foreach(['q','tipo','status','comunicacao','ordenar','direcao'] as $key){$value=(string)($filters[$key]??'');if($value!==''){$queryBase[$key]=$value;}}
$from=$total>0?(($page-1)*$perPage)+1:0;$to=min($total,$page*$perPage);
$currentSort=(string)($filters['ordenar']??'comunicacao');$currentDirection=(string)($filters['direcao']??'DESC');
$sortHeader=static function(string $label,string $key,string $defaultDirection='ASC') use($listUrl,$queryBase,$currentSort,$currentDirection,$perPage): string {
    $active=$currentSort===$key;$next=$active?($currentDirection==='ASC'?'DESC':'ASC'):$defaultDirection;
    $args=array_merge($queryBase,['ordenar'=>$key,'direcao'=>$next,'pagina'=>1,'por_pagina'=>$perPage]);
    $icon=!$active?'↕':($currentDirection==='ASC'?'↑':'↓');
    return '<a class="cif-sort-link'.($active?' is-active':'').'" href="'.esc_url(add_query_arg($args,$listUrl)).'">'.esc_html($label).'<span aria-hidden="true">'.$icon.'</span></a>';
};
require SMTT_CIF_GESTAO_PATH.'templates/layout/header.php';
?>
<section class="cif-page-head cif-page-head--compact">
    <div><p class="cif-eyebrow">Pós-emissão</p><h1>Ocorrências da CIF</h1><p>Perda, furto, roubo, dano, desgaste e recuperação.</p></div>
    <?php if($currentUser->can('cif.recolhimento.visualizar')): ?><a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/recolhimentos')) ?>">Recolhimentos</a><?php endif; ?>
</section>
<section class="cif-list-screen">
    <form method="get" action="<?= esc_url($listUrl) ?>" class="cif-list-filters">
        <div class="cif-filter-row cif-filter-row--wide">
            <label class="cif-field cif-field--grow"><span>Buscar</span><input type="search" name="q" value="<?= esc_attr((string)$filters['q']) ?>" placeholder="Nome, matrícula ou código CIF"></label>
            <label class="cif-field"><span>Tipo</span><select name="tipo"><option value="">Todos</option><?php foreach($typeOptions as $code=>$label): ?><option value="<?= esc_attr($code) ?>" <?= selected($filters['tipo'],$code,false) ?>><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
            <label class="cif-field"><span>Status</span><select name="status"><option value="">Todos</option><?php foreach($statusOptions as $code=>$label): ?><option value="<?= esc_attr($code) ?>" <?= selected($filters['status'],$code,false) ?>><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
            <label class="cif-field"><span>Comunicação em</span><input type="date" name="comunicacao" value="<?= esc_attr((string)($filters['comunicacao']??'')) ?>"></label>
            <div class="cif-filter-row__actions"><button class="cif-button cif-button--primary" type="submit">Buscar</button><a class="cif-button cif-button--ghost" data-no-modal href="<?= esc_url($listUrl) ?>">Limpar</a></div>
        </div>
    </form>
    <div class="cif-list-meta"><div><strong>Ocorrências registradas</strong><span><?= esc_html((string)$total) ?> registro(s)</span></div><div class="cif-list-range">Mostrando <?= esc_html((string)$from) ?>–<?= esc_html((string)$to) ?> de <?= esc_html((string)$total) ?></div></div>
    <div class="cif-list-scroll"><table class="cif-table"><thead><tr>
        <th><?= $sortHeader('Agente','nome','ASC') ?></th>
        <th><?= $sortHeader('CIF','codigo','ASC') ?></th>
        <th><?= $sortHeader('Ocorrência','tipo','ASC') ?></th>
        <th><?= $sortHeader('Status','status','ASC') ?></th>
        <th><?= $sortHeader('Comunicação','comunicacao','DESC') ?></th>
        <th class="cif-table__actions">Ações</th>
    </tr></thead><tbody>
    <?php if($occurrences===[]): ?><tr><td colspan="6" class="cif-empty">Nenhuma ocorrência encontrada.</td></tr><?php else: foreach($occurrences as $row): ?><tr>
        <td><strong><?= esc_html((string)$row['nome_completo']) ?></strong><br><span class="cif-muted cif-small">Matrícula <?= esc_html((string)$row['matricula']) ?></span></td>
        <td><code><?= esc_html((string)$row['codigo_validacao']) ?></code><br><span class="cif-muted cif-small"><?= esc_html((string)$row['status_validade']) ?> · <?= esc_html((string)$row['status_fisico']) ?></span></td>
        <td><?= esc_html((string)$row['tipo_label']) ?></td>
        <td><span class="cif-badge <?= (string)$row['status']==='ABERTA'?'cif-badge--warning':'cif-badge--success' ?>"><?= esc_html((string)$row['status_label']) ?></span></td>
        <td><?= esc_html($fmt($row['data_comunicacao'])) ?></td>
        <td class="cif-table__actions"><a class="cif-link-action" href="<?= esc_url(home_url('/cif/ocorrencias/'.rawurlencode((string)$row['uuid']))) ?>">Abrir</a></td>
    </tr><?php endforeach; endif; ?></tbody></table></div>
    <?php $itemLabel='ocorrência(s)'; require SMTT_CIF_GESTAO_PATH.'templates/layout/list-footer.php'; ?>
</section>
<?php require SMTT_CIF_GESTAO_PATH.'templates/layout/footer.php'; ?>
