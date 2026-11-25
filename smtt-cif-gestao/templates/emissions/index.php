<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
/** @var list<array<string,mixed>> $items */
$bodyClass='cif-page--list';
$fmt=static fn($value): string => is_string($value)&&$value!=='' ? wp_date('d/m/Y H:i',strtotime($value.' UTC')) : '—';
$listUrl=home_url('/cif/emissoes');
$queryBase=[];
foreach(['q','status','tipo','emitida','ordenar','direcao'] as $key){$value=(string)($filters[$key]??'');if($value!==''){$queryBase[$key]=$value;}}
$from=$total>0?(($page-1)*$perPage)+1:0;$to=min($total,$page*$perPage);
$currentSort=(string)($filters['ordenar']??'emitida');$currentDirection=(string)($filters['direcao']??'DESC');
$sortHeader=static function(string $label,string $key,string $defaultDirection='ASC') use($listUrl,$queryBase,$currentSort,$currentDirection,$perPage): string {
    $active=$currentSort===$key;$next=$active?($currentDirection==='ASC'?'DESC':'ASC'):$defaultDirection;
    $args=array_merge($queryBase,['ordenar'=>$key,'direcao'=>$next,'pagina'=>1,'por_pagina'=>$perPage]);
    $icon=!$active?'↕':($currentDirection==='ASC'?'↑':'↓');
    return '<a class="cif-sort-link'.($active?' is-active':'').'" href="'.esc_url(add_query_arg($args,$listUrl)).'">'.esc_html($label).'<span aria-hidden="true">'.$icon.'</span></a>';
};
require SMTT_CIF_GESTAO_PATH.'templates/layout/header.php';
?>
<section class="cif-page-head cif-page-head--compact">
    <div><p class="cif-eyebrow">Carteiras emitidas</p><h1>Emissões CIF</h1><p>Cada emissão possui código, token QR, estado e histórico próprios.</p></div>
    <?php if($canSetup): ?><a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/emissoes/preparacao')) ?>">Configurações de emissão</a><?php endif; ?>
</section>
<section class="cif-list-screen">
    <form method="get" action="<?= esc_url($listUrl) ?>" class="cif-list-filters">
        <div class="cif-filter-row cif-filter-row--wide">
            <label class="cif-field cif-field--grow"><span>Buscar</span><input type="search" name="q" value="<?= esc_attr((string)($filters['q']??'')) ?>" placeholder="Código, matrícula ou nome"></label>
            <label class="cif-field"><span>Validade</span><select name="status"><option value="">Todas</option><?php foreach(['PENDENTE_ATIVACAO'=>'Pendente de ativação','ATIVA'=>'Ativa','SUSPENSA'=>'Suspensa','ENCERRADA'=>'Encerrada'] as $code=>$label): ?><option value="<?= esc_attr($code) ?>" <?= selected((string)($filters['status']??''),$code,false) ?>><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
            <label class="cif-field"><span>Tipo</span><select name="tipo"><option value="">Todos</option><?php foreach($typeOptions as $code=>$label): ?><option value="<?= esc_attr($code) ?>" <?= selected((string)($filters['tipo']??''),$code,false) ?>><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
            <label class="cif-field"><span>Emitida em</span><input type="date" name="emitida" value="<?= esc_attr((string)($filters['emitida']??'')) ?>"></label>
            <div class="cif-filter-row__actions"><button class="cif-button cif-button--primary" type="submit">Buscar</button><a class="cif-button cif-button--ghost" data-no-modal href="<?= esc_url($listUrl) ?>">Limpar</a></div>
        </div>
    </form>
    <div class="cif-list-meta"><div><strong>Emissões</strong><span><?= esc_html((string)$total) ?> registro(s)</span></div><div class="cif-list-range">Mostrando <?= esc_html((string)$from) ?>–<?= esc_html((string)$to) ?> de <?= esc_html((string)$total) ?></div></div>
    <div class="cif-list-scroll"><table class="cif-table"><thead><tr>
        <th><?= $sortHeader('Código','codigo','ASC') ?></th>
        <th><?= $sortHeader('Agente','nome','ASC') ?></th>
        <th><?= $sortHeader('Tipo','tipo','ASC') ?></th>
        <th><?= $sortHeader('Emitida em','emitida','DESC') ?></th>
        <th><?= $sortHeader('Validade','validade','ASC') ?></th>
        <th><?= $sortHeader('Situação física','fisico','ASC') ?></th>
        <th class="cif-table__actions">Ações</th>
    </tr></thead><tbody>
    <?php if($items===[]): ?><tr><td colspan="7" class="cif-empty">Nenhuma emissão encontrada.</td></tr><?php else: foreach($items as $item): ?><tr>
        <td><strong><code><?= esc_html((string)$item['codigo_validacao']) ?></code></strong><br><span class="cif-muted cif-small">Ciclo <?= str_pad((string)$item['ciclo_numero'],2,'0',STR_PAD_LEFT) ?> · Seq. <?= str_pad((string)$item['sequencial_sistema'],4,'0',STR_PAD_LEFT) ?></span></td>
        <td><strong><?= esc_html((string)$item['nome_completo']) ?></strong><br><span class="cif-muted cif-small">Matrícula <?= esc_html((string)$item['matricula']) ?></span></td>
        <td><?= esc_html($typeOptions[(string)$item['tipo_emissao_codigo']] ?? (string)$item['tipo_emissao_codigo']) ?></td>
        <td><?= esc_html($fmt($item['emitida_em'])) ?></td>
        <td><span class="cif-badge cif-emission-status--<?= esc_attr(strtolower((string)$item['status_validade'])) ?>"><?= esc_html((string)$item['status_label']) ?></span></td>
        <td><span class="cif-badge"><?= esc_html((string)$item['status_fisico']) ?></span></td>
        <td class="cif-table__actions"><a class="cif-link-action" href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$item['uuid']))) ?>">Abrir</a></td>
    </tr><?php endforeach; endif; ?></tbody></table></div>
    <?php $itemLabel='emissão(ões)'; require SMTT_CIF_GESTAO_PATH.'templates/layout/list-footer.php'; ?>
</section>
<?php require SMTT_CIF_GESTAO_PATH.'templates/layout/footer.php'; ?>
