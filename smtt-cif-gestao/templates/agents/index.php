<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
/** @var list<array<string,mixed>> $items */
$bodyClass = 'cif-page--list';
$listUrl = home_url('/cif/agentes');
$queryBase = [];
foreach (['q','cpf','situacao','fonte','admissao','cadastro','atualizado','ativo','ordenar','direcao'] as $key) {
    $value = (string)($filters[$key] ?? '');
    if ($value !== '') { $queryBase[$key] = $value; }
}
$hasExtra = (string)($filters['cpf'] ?? '') !== '' || (string)($filters['fonte'] ?? '') !== ''
    || (string)($filters['admissao'] ?? '') !== '' || (string)($filters['cadastro'] ?? '') !== ''
    || (string)($filters['atualizado'] ?? '') !== '';
$from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
$to = min($total, $page * $perPage);
$currentSort = (string)($filters['ordenar'] ?? 'nome');
$currentDirection = (string)($filters['direcao'] ?? 'ASC');
$sortHeader = static function (string $label, string $key, string $defaultDirection = 'ASC') use ($listUrl, $queryBase, $currentSort, $currentDirection, $perPage): string {
    $active = $currentSort === $key;
    $next = $active ? ($currentDirection === 'ASC' ? 'DESC' : 'ASC') : $defaultDirection;
    $args = array_merge($queryBase, ['ordenar'=>$key,'direcao'=>$next,'pagina'=>1,'por_pagina'=>$perPage]);
    $icon = !$active ? '↕' : ($currentDirection === 'ASC' ? '↑' : '↓');
    return '<a class="cif-sort-link'.($active?' is-active':'').'" href="'.esc_url(add_query_arg($args,$listUrl)).'">'.esc_html($label).'<span aria-hidden="true">'.$icon.'</span></a>';
};
$exportArgs = $queryBase;
unset($exportArgs['ordenar'], $exportArgs['direcao']);
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-page-head cif-page-head--compact">
    <div><p class="cif-eyebrow">Cadastro funcional</p><h1>Agentes de Trânsito</h1><p>Cadastro, biometria e histórico funcional em uma única ficha.</p></div>
    <div class="cif-inline-actions">
        <?php if ($canImport): ?><a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/agentes/importar')) ?>">Importar lista</a><?php endif; ?>
        <?php if ($canCreate): ?><a class="cif-button cif-button--primary" href="<?= esc_url(home_url('/cif/agentes/novo')) ?>">Cadastrar agente</a><?php endif; ?>
    </div>
</section>
<?php if ($notice !== null): ?><div class="cif-alert cif-alert--<?= esc_attr($notice['type']) ?>"><?= esc_html($notice['text']) ?></div><?php endif; ?>
<?php if ($filterError !== null): ?><div class="cif-alert cif-alert--error"><?= esc_html($filterError) ?></div><?php endif; ?>

<section class="cif-list-screen">
    <form method="get" action="<?= esc_url($listUrl) ?>" class="cif-list-filters">
        <div class="cif-filter-row cif-filter-row--agents">
            <label class="cif-field cif-field--grow"><span>Buscar</span><input type="search" name="q" value="<?= esc_attr((string)($filters['q'] ?? '')) ?>" placeholder="Nome ou matrícula"></label>
            <label class="cif-field"><span>Situação</span><select name="situacao"><option value="">Todas</option><?php foreach ($statusOptions as $code=>$label): ?><option value="<?= esc_attr($code) ?>" <?= selected((string)($filters['situacao'] ?? ''),$code,false) ?>><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
            <label class="cif-field"><span>Cadastro</span><select name="ativo"><option value="">Todos</option><option value="1" <?= selected((string)($filters['ativo'] ?? ''),'1',false) ?>>Ativos</option><option value="0" <?= selected((string)($filters['ativo'] ?? ''),'0',false) ?>>Arquivados</option></select></label>
            <div class="cif-filter-row__actions"><button class="cif-button cif-button--primary" type="submit">Buscar</button><a class="cif-button cif-button--ghost" data-no-modal href="<?= esc_url($listUrl) ?>">Limpar</a></div>
        </div>
        <details class="cif-compact-filters cif-compact-filters--simple" <?= $hasExtra ? 'open' : '' ?>>
            <summary>Mais filtros</summary>
            <div class="cif-filter-grid cif-filter-grid--simple">
                <?php if ($canViewSensitive): ?><label class="cif-field"><span>CPF exato</span><input type="text" name="cpf" value="<?= esc_attr((string)($filters['cpf'] ?? '')) ?>" placeholder="000.000.000-00"></label><?php endif; ?>
                <label class="cif-field"><span>Fonte</span><select name="fonte"><option value="">Todas</option><?php foreach ($sourceOptions as $code=>$label): ?><option value="<?= esc_attr($code) ?>" <?= selected((string)($filters['fonte'] ?? ''),$code,false) ?>><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
                <label class="cif-field"><span>Admissão</span><input type="date" name="admissao" value="<?= esc_attr((string)($filters['admissao'] ?? '')) ?>"></label>
                <label class="cif-field"><span>Cadastrado em</span><input type="date" name="cadastro" value="<?= esc_attr((string)($filters['cadastro'] ?? '')) ?>"></label>
                <label class="cif-field"><span>Atualizado em</span><input type="date" name="atualizado" value="<?= esc_attr((string)($filters['atualizado'] ?? '')) ?>"></label>
                <div class="cif-filter-actions"><button class="cif-button cif-button--secondary" type="submit">Aplicar</button><?php if ($canExport): ?><a class="cif-button cif-button--ghost" href="<?= esc_url(add_query_arg($exportArgs,home_url('/cif/agentes/exportar'))) ?>">Exportar CSV</a><?php endif; ?></div>
            </div>
        </details>
    </form>

    <div class="cif-list-meta"><div><strong>Agentes cadastrados</strong><span><?= esc_html((string)$total) ?> registro(s)</span></div><div class="cif-list-range">Mostrando <?= esc_html((string)$from) ?>–<?= esc_html((string)$to) ?> de <?= esc_html((string)$total) ?></div></div>
    <div class="cif-list-scroll"><table class="cif-table"><thead><tr>
        <th><?= $sortHeader('Agente','nome','ASC') ?></th>
        <th><?= $sortHeader('Matrícula','matricula','ASC') ?></th>
        <th><?= $sortHeader('Situação','situacao','ASC') ?></th>
        <th><?= $sortHeader('Cadastro','ativo','DESC') ?></th>
        <th><?= $sortHeader('Admissão','admissao','DESC') ?></th>
        <th><?= $sortHeader('Atualizado','atualizado','DESC') ?></th>
        <th class="cif-table__actions">Ações</th>
    </tr></thead><tbody>
    <?php if ($items === []): ?><tr><td colspan="7" class="cif-empty">Nenhum agente localizado.</td></tr><?php else: foreach ($items as $agent): ?><tr>
        <td><strong><?= esc_html((string)$agent['nome_completo']) ?></strong><br><span class="cif-small cif-muted">v<?= esc_html((string)$agent['dados_versao']) ?> · <?= esc_html($sourceOptions[(string)$agent['fonte']] ?? (string)$agent['fonte']) ?></span></td>
        <td><?= esc_html((string)$agent['matricula']) ?></td>
        <td><span class="cif-badge"><?= esc_html((string)$agent['situacao_funcional_atual']) ?></span></td>
        <td><span class="cif-badge <?= (int)$agent['ativo'] === 1 ? 'cif-badge--success' : 'cif-badge--warning' ?>"><?= (int)$agent['ativo'] === 1 ? 'Ativo' : 'Arquivado' ?></span></td>
        <td><?= !empty($agent['data_admissao']) ? esc_html(wp_date('d/m/Y',strtotime((string)$agent['data_admissao'].' UTC'))) : '—' ?></td>
        <td><?= esc_html(mysql2date('d/m/Y H:i',(string)$agent['atualizado_em'].' UTC',true)) ?></td>
        <td class="cif-table__actions"><a class="cif-link-action" href="<?= esc_url(home_url('/cif/agentes/'.rawurlencode((string)$agent['uuid']))) ?>">Abrir ficha</a><?php if ($canEdit): ?><a class="cif-link-action" href="<?= esc_url(home_url('/cif/agentes/'.rawurlencode((string)$agent['uuid']).'/editar')) ?>">Editar</a><?php endif; ?></td>
    </tr><?php endforeach; endif; ?></tbody></table></div>
    <?php $itemLabel='agente(s)'; require SMTT_CIF_GESTAO_PATH . 'templates/layout/list-footer.php'; ?>
</section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
