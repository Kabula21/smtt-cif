<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var array<string,mixed> $filters */
/** @var int $page */
/** @var int $pages */
/** @var int $perPage */
/** @var bool $canCapture */
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';

$queryArgs = [];
foreach (['q', 'biometria', 'situacao'] as $key) {
    $value = (string) ($filters[$key] ?? '');
    if ($value !== '') {
        $queryArgs[$key] = $value;
    }
}
$queryArgs['por_pagina'] = $perPage;

$functionalStatuses = [
    'ATIVO' => 'Ativo',
    'AFASTADO' => 'Afastado',
    'EXONERADO' => 'Exonerado',
    'VACANCIA' => 'Vacância',
    'FALECIDO' => 'Falecido',
];
?>
<section class="cif-page-head">
    <div>
        <p class="cif-eyebrow">Dados biométricos</p>
        <h1>Biometria</h1>
        <p>Selecione o Agente de Trânsito para consultar o histórico ou registrar fotografia e assinatura.</p>
    </div>
</section>

<section class="cif-panel">
    <form class="cif-filter-grid" method="get" action="<?= esc_url(home_url('/cif/biometria')) ?>">
        <label class="cif-field cif-field--span-2">
            <span>Nome ou matrícula</span>
            <input type="text" name="q" value="<?= esc_attr((string) ($filters['q'] ?? '')) ?>" maxlength="200" autocomplete="off" placeholder="Digite parte do nome ou da matrícula">
        </label>
        <label class="cif-field">
            <span>Situação biométrica</span>
            <select name="biometria">
                <option value="">Todas</option>
                <option value="valida" <?= (string) ($filters['biometria'] ?? '') === 'valida' ? 'selected' : '' ?>>Com coleta válida</option>
                <option value="sem_valida" <?= (string) ($filters['biometria'] ?? '') === 'sem_valida' ? 'selected' : '' ?>>Sem coleta válida</option>
                <option value="sem_coleta" <?= (string) ($filters['biometria'] ?? '') === 'sem_coleta' ? 'selected' : '' ?>>Nunca coletada</option>
                <option value="somente_invalidada" <?= (string) ($filters['biometria'] ?? '') === 'somente_invalidada' ? 'selected' : '' ?>>Somente coletas invalidadas</option>
            </select>
        </label>
        <label class="cif-field">
            <span>Situação funcional</span>
            <select name="situacao">
                <option value="">Todas</option>
                <?php foreach ($functionalStatuses as $code => $label): ?>
                    <option value="<?= esc_attr($code) ?>" <?= (string) ($filters['situacao'] ?? '') === $code ? 'selected' : '' ?>><?= esc_html($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="cif-field">
            <span>Por página</span>
            <select name="por_pagina">
                <?php foreach ([25, 50, 100] as $size): ?>
                    <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="cif-filter-actions cif-field--span-full">
            <button class="cif-button cif-button--secondary" type="submit">Aplicar filtros</button>
            <a class="cif-button cif-button--ghost" data-no-modal href="<?= esc_url(home_url('/cif/biometria')) ?>">Limpar filtros</a>
        </div>
    </form>
</section>

<section class="cif-section">
    <div class="cif-section__head">
        <div>
            <h2>Agentes</h2>
            <p><?= esc_html((string) $total) ?> agente(s) encontrado(s).</p>
        </div>
    </div>

    <div class="cif-table-wrap">
        <table class="cif-table">
            <thead>
            <tr>
                <th>Agente</th>
                <th>Matrícula</th>
                <th>Situação funcional</th>
                <th>Biometria</th>
                <th>Última coleta válida</th>
                <th>Histórico</th>
                <th class="cif-table__actions">Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($items === []): ?>
                <tr><td colspan="7" class="cif-empty">Nenhum agente localizado para os filtros informados.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $agent): ?>
                    <?php $hasValid = (int) $agent['coletas_validas'] > 0; ?>
                    <tr>
                        <td><strong><?= esc_html((string) $agent['nome_completo']) ?></strong></td>
                        <td><?= esc_html((string) $agent['matricula']) ?></td>
                        <td><span class="cif-badge"><?= esc_html((string) $agent['situacao_funcional_atual']) ?></span></td>
                        <td>
                            <?php if ($hasValid): ?>
                                <span class="cif-badge cif-badge--success">Coleta válida</span>
                            <?php elseif ((int) $agent['total_coletas'] > 0): ?>
                                <span class="cif-badge cif-badge--warning">Sem coleta válida</span>
                            <?php else: ?>
                                <span class="cif-badge cif-badge--warning">Não coletada</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($agent['ultima_coleta_valida_em']) ? esc_html(mysql2date('d/m/Y H:i', (string) $agent['ultima_coleta_valida_em'] . ' UTC', true)) : '—' ?></td>
                        <td><?= esc_html((string) $agent['total_coletas']) ?> coleta(s)</td>
                        <td class="cif-table__actions">
                            <a class="cif-link-action" href="<?= esc_url(home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']) . '/biometria')) ?>"><?= $canCapture ? 'Gerenciar' : 'Visualizar' ?></a>
                            <a class="cif-link-action" href="<?= esc_url(home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']))) ?>">Ficha</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="cif-pagination" aria-label="Paginação da biometria">
            <?php for ($i = 1; $i <= $pages; ++$i): ?>
                <?php $url = add_query_arg([...$queryArgs, 'pagina' => $i], home_url('/cif/biometria')); ?>
                <a class="<?= $i === $page ? 'is-current' : '' ?>" href="<?= esc_url($url) ?>"><?= esc_html((string) $i) ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
