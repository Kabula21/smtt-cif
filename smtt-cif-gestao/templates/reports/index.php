<?php
$bodyClass = 'cif-page--list';
$fmt = static function ($value): string {
    if (!is_string($value) || $value === '') return '—';
    $hasTime = str_contains($value, ':');
    $ts = strtotime($value . ($hasTime ? ' UTC' : ''));
    return $ts ? wp_date($hasTime ? 'd/m/Y H:i' : 'd/m/Y', $ts) : $value;
};
$queryBase = [
    'tipo'=>$type,
    'q'=>$filters['q'],
    'status'=>$filters['status'],
    'data'=>$filters['data'],
    'ordem'=>$sort,
    'direcao'=>$direction,
];
$listUrl = home_url('/cif/relatorios');
$itemLabel = 'registro(s)';
$from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
$to = min($total, $page * $perPage);
$currentReportUrl = add_query_arg(array_filter(array_merge($queryBase, ['pagina'=>$page,'por_pagina'=>$perPage]), static fn($v)=>$v!==''), $listUrl);

$reportTitle = static function (string $reportType, array $row): string {
    return match ($reportType) {
        'agentes' => trim((string)($row['nome_completo'] ?? '')) ?: 'Ficha do agente',
        'processos' => 'Processo ' . ((string)($row['numero_protocolo'] ?? '') ?: '#' . (string)($row['ciclo_numero'] ?? '')),
        'emissoes' => 'CIF ' . ((string)($row['codigo_validacao'] ?? '') ?: (string)($row['sequencial_sistema'] ?? '')),
        'ocorrencias' => 'Ocorrência ' . ((string)($row['tipo_codigo'] ?? '') ?: (string)($row['codigo_validacao'] ?? '')),
        'recolhimentos' => 'Recolhimento ' . ((string)($row['codigo_validacao'] ?? '') ?: (string)($row['matricula'] ?? '')),
        default => 'Relatório do registro',
    };
};
$reportDescription = static function (string $reportType): string {
    return match ($reportType) {
        'agentes' => 'Resumo cadastral do agente conforme os dados disponíveis no relatório.',
        'processos' => 'Resumo do processo de emissão e sua situação atual.',
        'emissoes' => 'Resumo da emissão da Carteira de Identificação Funcional e seu estado atual.',
        'ocorrencias' => 'Resumo da ocorrência vinculada à Carteira de Identificação Funcional.',
        'recolhimentos' => 'Resumo do procedimento de recolhimento da Carteira de Identificação Funcional.',
        default => 'Resumo do registro consultado.',
    };
};

$auditRecordAction = static function (array $row) use ($currentReportUrl): ?array {
    $uuid = trim((string)($row['entidade_uuid'] ?? ''));
    if ($uuid === '') return null;
    $entity = strtolower(trim((string)($row['entidade_tipo'] ?? '')));
    $path = match ($entity) {
        'agente' => '/cif/agentes/' . rawurlencode($uuid),
        'processo_emissao' => '/cif/processos/' . rawurlencode($uuid),
        'emissao', 'emissao_cif' => '/cif/emissoes/' . rawurlencode($uuid),
        'ocorrencia_cif' => '/cif/ocorrencias/' . rawurlencode($uuid),
        'recolhimento_cif' => '/cif/recolhimentos/' . rawurlencode($uuid),
        default => null,
    };
    return $path === null ? null : ['label'=>'Ver registro','url'=>add_query_arg('retorno',$currentReportUrl,home_url($path))];
};

$sortUrl = static function (string $key) use ($queryBase, $listUrl, $sort, $direction): string {
    $next = ($sort === $key && $direction === 'ASC') ? 'DESC' : 'ASC';
    $args = $queryBase;
    $args['ordem'] = $key;
    $args['direcao'] = $next;
    $args['pagina'] = 1;
    return add_query_arg(array_filter($args, static fn($v)=>$v!==''), $listUrl);
};

require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-page-head cif-page-head--compact">
    <div>
        <p class="cif-eyebrow">Consulta gerencial</p>
        <h1>Relatórios</h1>
        <p>Consulte os registros consolidados, visualize o relatório detalhado e imprima sem sair do módulo.</p>
    </div>
</section>

<div class="cif-kpi-grid cif-kpi-grid--reports">
    <div class="cif-kpi"><span>Agentes ativos</span><strong><?= esc_html((string)($summary['agentes_ativos'] ?? 0)) ?></strong></div>
    <div class="cif-kpi"><span>Processos abertos</span><strong><?= esc_html((string)($summary['processos_abertos'] ?? 0)) ?></strong></div>
    <div class="cif-kpi"><span>CIF ativas</span><strong><?= esc_html((string)($summary['cif_ativas'] ?? 0)) ?></strong></div>
</div>

<div class="cif-report-workspace">
<nav class="cif-report-tabs" aria-label="Tipos de relatório">
    <?php foreach ($typeOptions as $code=>$label): ?>
        <a class="cif-report-tab <?= $type === $code ? 'is-active' : '' ?>" href="<?= esc_url(add_query_arg('tipo',$code,$listUrl)) ?>" <?= $type === $code ? 'aria-current="page"' : '' ?>><?= esc_html($label) ?></a>
    <?php endforeach; ?>
</nav>

<section class="cif-list-screen cif-report-screen">
    <form method="get" action="<?= esc_url($listUrl) ?>" class="cif-list-filters">
        <input type="hidden" name="tipo" value="<?= esc_attr($type) ?>">
        <div class="cif-filter-grid cif-filter-grid--report">
            <label class="cif-field cif-field--grow"><span>Buscar</span><input type="search" name="q" value="<?= esc_attr($filters['q']) ?>" placeholder="<?= esc_attr($type === 'atividade_usuarios' ? 'Usuário, setor, ação ou registro' : 'Nome, matrícula, código ou protocolo') ?>"></label>
            <?php if ($statusOptions !== []): ?><label class="cif-field"><span>Status</span><select name="status"><option value="">Todos</option><?php foreach ($statusOptions as $code=>$label): ?><option value="<?= esc_attr($code) ?>" <?= selected($filters['status'],$code,false) ?>><?= esc_html($label) ?></option><?php endforeach; ?></select></label><?php endif; ?>
            <label class="cif-field"><span>Data</span><input type="date" name="data" value="<?= esc_attr($filters['data']) ?>"></label>
            <div class="cif-filter-actions"><button class="cif-button cif-button--primary" type="submit">Buscar</button><a class="cif-button cif-button--ghost" href="<?= esc_url(add_query_arg('tipo',$type,$listUrl)) ?>">Limpar</a></div>
        </div>
    </form>

    <div class="cif-list-meta cif-report-toolbar">
        <div class="cif-report-toolbar__title">
            <strong><?= esc_html($typeOptions[$type]) ?></strong>
            <span><?= esc_html((string)$total) ?> registro(s)</span>
        </div>
        <div class="cif-report-toolbar__actions">
            <span class="cif-list-range">Mostrando <?= esc_html((string)$from) ?>–<?= esc_html((string)$to) ?> de <?= esc_html((string)$total) ?></span>
            <?php if ($canExport): ?><a class="cif-button cif-button--secondary cif-report-export" href="<?= esc_url(add_query_arg(array_filter($queryBase, static fn($v)=>$v!==''), home_url('/cif/relatorios/exportar'))) ?>">Exportar CSV</a><?php endif; ?>
        </div>
    </div>

    <div class="cif-list-scroll">
        <table class="cif-table cif-report-table">
            <thead><tr>
                <?php foreach ($columns as $key=>$label): ?><th>
                    <?php if (in_array($key,$sortable,true)): ?><a class="cif-sort-link <?= $sort===$key?'is-active':'' ?>" href="<?= esc_url($sortUrl($key)) ?>"><?= esc_html($label) ?><span aria-hidden="true"><?= $sort===$key ? ($direction==='ASC'?'↑':'↓') : '↕' ?></span></a><?php else: ?><?= esc_html($label) ?><?php endif; ?>
                </th><?php endforeach; ?>
                <th class="cif-table__actions">Ações</th>
            </tr></thead>
            <tbody>
            <?php if ($items === []): ?><tr><td colspan="<?= count($columns)+1 ?>" class="cif-empty">Nenhum registro encontrado para os filtros selecionados.</td></tr>
            <?php else: foreach ($items as $row): ?>
                <tr>
                    <?php foreach (array_keys($columns) as $key): $value=$row[$key] ?? null; ?>
                        <td>
                        <?php if ($type === 'atividade_usuarios' && $key === 'actor_nome'): ?>
                            <strong><?= esc_html((string)($row['actor_nome'] ?? 'Sistema')) ?></strong><br><span class="cif-small cif-muted"><?= esc_html((string)($row['actor_login'] ?? '—')) ?></span>
                        <?php elseif ($type === 'atividade_usuarios' && $key === 'entidade_tipo'): ?>
                            <?= esc_html((string)($row['entidade_tipo'] ?: '—')) ?><br><span class="cif-small cif-muted"><?= esc_html((string)($row['entidade_uuid'] ?: ($row['entidade_id'] ?: '—'))) ?></span>
                        <?php elseif (in_array($key,['criado_em','atualizado_em','emitida_em','ativada_em','entregue_em','data_fato','data_comunicacao','recuperada_em','recolhimento_solicitado_em','recolhimento_devido_em','recolhida_em','inutilizada_em','elegivel_emissao_em','ocorrido_em','data_admissao'],true)): ?>
                            <?= esc_html($fmt($value)) ?>
                        <?php else: ?><?= esc_html((string)($value ?? '') !== '' ? (string)$value : '—') ?><?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="cif-table__actions">
                        <div class="cif-table-action-stack">
                        <?php if ($type === 'atividade_usuarios'): $modalId='report-audit-'.(int)$row['__audit_id']; ?>
                            <button type="button" class="cif-report-action" data-modal-open="<?= esc_attr($modalId) ?>">Detalhes</button>
                        <?php else: $modalId='report-record-'.substr(md5($type.'|'.(string)($row['__uuid'] ?? serialize($row))),0,12); ?>
                            <button type="button" class="cif-report-action" data-modal-open="<?= esc_attr($modalId) ?>">Ver detalhes</button>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/list-footer.php'; ?>
</section>
</div>

<?php if ($type !== 'atividade_usuarios'): foreach ($items as $row):
    $modalId='report-record-'.substr(md5($type.'|'.(string)($row['__uuid'] ?? serialize($row))),0,12);
    $title=$reportTitle($type,$row);
?>
<div class="cif-modal cif-report-modal" id="<?= esc_attr($modalId) ?>" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="<?= esc_attr($modalId) ?>-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog cif-modal__dialog--wide cif-report-modal__dialog">
        <div class="cif-modal__head cif-report-print-head">
            <div>
                <span class="cif-modal__eyebrow">Relatório · <?= esc_html($typeOptions[$type]) ?></span>
                <h2 id="<?= esc_attr($modalId) ?>-title"><?= esc_html($title) ?></h2>
                <p><?= esc_html($reportDescription($type)) ?></p>
            </div>
            <button type="button" class="cif-modal__close cif-no-print" data-modal-close aria-label="Fechar">×</button>
        </div>
        <div class="cif-modal__body">
            <div class="cif-report-print-meta">
                <strong>Sistema CIF · SMTT</strong>
                <span>Gerado em <?= esc_html(wp_date('d/m/Y H:i')) ?></span>
            </div>
            <div class="cif-report-print-content" data-report-print-title="<?= esc_attr($title) ?>">
                <table class="cif-report-detail-table">
                    <tbody>
                    <?php foreach ($columns as $key=>$label): $value=$row[$key] ?? null; ?>
                        <tr>
                            <th><?= esc_html($label) ?></th>
                            <td><?php if (in_array($key,['criado_em','atualizado_em','emitida_em','ativada_em','entregue_em','data_fato','data_comunicacao','recuperada_em','recolhimento_solicitado_em','recolhimento_devido_em','recolhida_em','inutilizada_em','elegivel_emissao_em','ocorrido_em','data_admissao'],true)): ?><?= esc_html($fmt($value)) ?><?php else: ?><?= esc_html((string)($value ?? '') !== '' ? (string)$value : '—') ?><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="cif-report-print-note">Documento gerado a partir dos dados registrados no Sistema CIF na data e hora indicadas acima.</div>
            </div>
        </div>
        <div class="cif-modal__footer cif-no-print">
            <button type="button" class="cif-button cif-button--ghost" data-modal-close>Fechar</button>
            <button type="button" class="cif-button cif-button--primary" data-report-print>Imprimir relatório</button>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>

<?php if ($type === 'atividade_usuarios'): foreach ($items as $row): $modalId='report-audit-'.(int)$row['__audit_id']; ?>
<div class="cif-modal cif-report-modal" id="<?= esc_attr($modalId) ?>" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="<?= esc_attr($modalId) ?>-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog cif-modal__dialog--wide cif-report-modal__dialog">
        <div class="cif-modal__head cif-report-print-head">
            <div><span class="cif-modal__eyebrow">Atividade #<?= (int)$row['__audit_id'] ?></span><h2 id="<?= esc_attr($modalId) ?>-title"><?= esc_html((string)$row['operacao']) ?></h2><p><?= esc_html($fmt((string)$row['ocorrido_em'])) ?></p></div>
            <button type="button" class="cif-modal__close cif-no-print" data-modal-close aria-label="Fechar">×</button>
        </div>
        <div class="cif-modal__body">
            <div class="cif-report-print-meta"><strong>Sistema CIF · SMTT · Auditoria</strong><span>Gerado em <?= esc_html(wp_date('d/m/Y H:i')) ?></span></div>
            <div class="cif-report-print-content" data-report-print-title="Atividade #<?= (int)$row['__audit_id'] ?> · <?= esc_attr((string)$row['operacao']) ?>">
                <table class="cif-report-detail-table"><tbody>
                    <tr><th>Data/hora</th><td><?= esc_html($fmt((string)$row['ocorrido_em'])) ?></td></tr>
                    <tr><th>Usuário</th><td><?= esc_html((string)($row['actor_nome'] ?: 'Sistema')) ?> · <?= esc_html((string)($row['actor_login'] ?: '—')) ?></td></tr>
                    <tr><th>Setor / perfil</th><td><?= esc_html((string)($row['setor'] ?: '—')) ?> · <?= esc_html((string)($row['perfil'] ?: '—')) ?></td></tr>
                    <tr><th>Ação</th><td><?= esc_html((string)($row['operacao'] ?: '—')) ?></td></tr>
                    <tr><th>Registro afetado</th><td><?= esc_html((string)($row['entidade_tipo'] ?: '—')) ?> · <?= esc_html((string)($row['entidade_uuid'] ?: ($row['entidade_id'] ?: '—'))) ?></td></tr>
                    <tr><th>Origem / IP</th><td><?= esc_html((string)($row['origem'] ?: '—')) ?> · <?= esc_html((string)($row['ip_origem_texto'] ?: '—')) ?></td></tr>
                    <tr><th>Request ID</th><td><?= esc_html((string)($row['request_id'] ?: '—')) ?></td></tr>
                    <tr><th>Justificativa</th><td><?= esc_html((string)($row['justificativa'] ?: '—')) ?></td></tr>
                    <tr><th>Integridade</th><td><?= $row['record_hash'] ? 'Registro encadeado' : 'Registro legado' ?></td></tr>
                </tbody></table>
                <h3>Valor anterior</h3>
                <pre class="cif-code-block"><?= esc_html((string)($row['valor_anterior'] ?: '—')) ?></pre>
                <h3>Valor posterior</h3>
                <pre class="cif-code-block"><?= esc_html((string)($row['valor_posterior'] ?: '—')) ?></pre>
                <div class="cif-report-print-note">Relatório de auditoria gerado a partir dos dados registrados no Sistema CIF.</div>
            </div>
        </div>
        <div class="cif-modal__footer cif-no-print">
            <button type="button" class="cif-button cif-button--ghost" data-modal-close>Fechar</button>
            <button type="button" class="cif-button cif-button--primary" data-report-print>Imprimir detalhes</button>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>

<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
