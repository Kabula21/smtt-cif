<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
/** @var array<string,mixed> $agent */
/** @var bool $canEdit */
/** @var bool $canDelete */
/** @var bool $canViewSensitive */
/** @var bool $canViewBiometry */
/** @var bool $canCaptureBiometry */
/** @var bool $canCreateProcess */
/** @var array<string,mixed>|null $biometry */
/** @var array<string,int> $summary */
/** @var array<string,int> $dependencies */
/** @var list<array<string,mixed>> $recentProcesses */
/** @var list<array<string,mixed>> $recentEmissions */
/** @var list<array<string,mixed>> $recentOccurrences */
/** @var array{type:string,text:string}|null $notice */
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
$currentBio = $biometry['current'] ?? null;
$photoUrl = $currentBio && !empty($currentBio['foto_arquivo_uuid'])
    ? home_url('/cif/biometria/arquivo/' . rawurlencode((string) $currentBio['foto_arquivo_uuid']))
    : null;
$hasIssuedEmission = (int) ($summary['emissoes_total'] ?? 0) > 0;
$removable = !$hasIssuedEmission;
$fmt = static fn($value): string => is_string($value) && $value !== '' ? wp_date('d/m/Y H:i', strtotime($value . ' UTC')) : '—';

$returnToReport = null;
if (isset($_GET['retorno'])) {
    $candidate = wp_validate_redirect((string)wp_unslash($_GET['retorno']), '');
    $reportsPath = wp_parse_url(home_url('/cif/relatorios'), PHP_URL_PATH);
    $candidatePath = $candidate !== '' ? wp_parse_url($candidate, PHP_URL_PATH) : null;
    if (is_string($candidate) && $candidate !== '' && is_string($reportsPath) && $candidatePath === $reportsPath) {
        $returnToReport = $candidate;
    }
}
?>

<section class="cif-agent-hero">
    <div class="cif-agent-hero__photo">
        <?php if ($photoUrl !== null): ?><img src="<?= esc_url($photoUrl) ?>" alt="Fotografia de <?= esc_attr((string) $agent['nome_completo']) ?>"><?php else: ?><?= esc_html(strtoupper(function_exists('mb_substr') ? mb_substr((string) $agent['nome_completo'], 0, 1, 'UTF-8') : substr((string) $agent['nome_completo'], 0, 1))) ?><?php endif; ?>
    </div>
    <div class="cif-agent-hero__body">
        <p class="cif-eyebrow">Ficha completa do Agente</p>
        <h1><?= esc_html((string) $agent['nome_completo']) ?></h1>
        <div class="cif-agent-hero__meta">
            <span>Matrícula <strong><?= esc_html((string) $agent['matricula']) ?></strong></span>
            <span><span class="cif-badge <?= (int) $agent['ativo'] === 1 ? 'cif-badge--success' : 'cif-badge--warning' ?>"><?= (int) $agent['ativo'] === 1 ? 'Cadastro ativo' : 'Cadastro arquivado' ?></span></span>
            <span><span class="cif-badge"><?= esc_html((string) $agent['situacao_funcional_label']) ?></span></span>
        </div>
    </div>
    <div class="cif-agent-hero__actions">
        <?php if ($returnToReport !== null): ?><a class="cif-button cif-button--ghost" href="<?= esc_url($returnToReport) ?>">Voltar ao relatório</a><?php endif; ?>
        <button class="cif-button cif-button--primary" type="button" data-modal-open="cif-agent-actions-modal">Ações do agente</button>
    </div>
</section>

<?php if ($notice !== null): ?><div class="cif-alert cif-alert--<?= esc_attr($notice['type']) ?>"><?= esc_html($notice['text']) ?></div><?php endif; ?>

<nav class="cif-profile-tabs" aria-label="Seções da ficha do Agente">
    <a href="#resumo">Resumo</a><a href="#dados">Dados cadastrais</a>
    <?php if ($canViewBiometry): ?><a href="#biometria">Biometria</a><?php endif; ?>
    <a href="#processos">Processos</a><a href="#emissoes">Emissões</a><a href="#historico">Histórico</a>
</nav>

<section id="resumo" class="cif-profile-section">
    <div class="cif-kpi-grid">
        <div class="cif-kpi"><span>Biometria válida</span><strong><?= (int) ($summary['biometrias_validas'] ?? 0) > 0 ? 'Sim' : 'Não' ?></strong></div>
        <div class="cif-kpi"><span>Processos abertos</span><strong><?= esc_html((string) ($summary['processos_abertos'] ?? 0)) ?></strong></div>
        <div class="cif-kpi"><span>Emissões</span><strong><?= esc_html((string) ($summary['emissoes_total'] ?? 0)) ?></strong></div>
        <div class="cif-kpi"><span>CIF ativa</span><strong><?= (int) ($summary['cif_ativa'] ?? 0) > 0 ? 'Sim' : 'Não' ?></strong></div>
    </div>
</section>

<?php
$hasBiometry = (int) ($summary['biometrias_validas'] ?? 0) > 0;
$hasProcess = $recentProcesses !== [];
$hasEmission = (int) ($summary['emissoes_total'] ?? 0) > 0;
$hasActiveCif = (int) ($summary['cif_ativa'] ?? 0) > 0;
$nextAction = null;
if ((int) $agent['ativo'] === 1) {
    if (!$hasBiometry && $canViewBiometry) {
        $nextAction = ['label' => $canCaptureBiometry ? 'Cadastrar biometria' : 'Consultar biometria', 'url' => home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']) . '/biometria')];
    } elseif (!$hasProcess && $canCreateProcess) {
        $nextAction = ['label' => 'Abrir primeiro processo', 'url' => add_query_arg('agente', (string) $agent['uuid'], home_url('/cif/processos/novo'))];
    } elseif (!$hasEmission && $recentProcesses !== []) {
        $nextAction = ['label' => 'Continuar processo', 'url' => home_url('/cif/processos/' . rawurlencode((string) $recentProcesses[0]['uuid']))];
    } elseif (!$hasActiveCif && $recentEmissions !== []) {
        $nextAction = ['label' => 'Continuar emissão', 'url' => home_url('/cif/emissoes/' . rawurlencode((string) $recentEmissions[0]['uuid']))];
    }
}
?>
<section class="cif-profile-section cif-workflow-panel cif-workflow-panel--compact" aria-labelledby="cif-workflow-title">
    <div class="cif-section__head">
        <div><h2 id="cif-workflow-title">Fluxo da Carteira Funcional</h2><p>Acompanhe a jornada deste agente do cadastro até a entrega e ativação da CIF.</p></div>
        <button class="cif-button cif-button--secondary" type="button" data-modal-open="cif-agent-flow-modal">Ver fluxo completo</button>
    </div>
    <div class="cif-workflow-compact" aria-label="Resumo do fluxo">
        <span class="is-complete"><strong>1</strong> Cadastro</span>
        <span class="<?= $hasBiometry ? 'is-complete' : 'is-pending' ?>"><strong>2</strong> Biometria</span>
        <span class="<?= $hasProcess ? 'is-complete' : 'is-pending' ?>"><strong>3</strong> Processo</span>
        <span class="<?= $hasEmission ? 'is-complete' : 'is-pending' ?>"><strong>4</strong> Emissão</span>
        <span class="<?= $hasActiveCif ? 'is-complete' : 'is-pending' ?>"><strong>5</strong> Entrega</span>
    </div>
</section>

<div class="cif-modal" id="cif-agent-flow-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cif-agent-flow-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog cif-modal__dialog--wide" role="document">
        <div class="cif-modal__head"><div><span class="cif-modal__eyebrow">Jornada operacional</span><h2 id="cif-agent-flow-title">Fluxo da Carteira Funcional</h2><p><?= esc_html((string) $agent['nome_completo']) ?> · matrícula <?= esc_html((string) $agent['matricula']) ?></p></div><button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button></div>
        <div class="cif-modal__body">
            <ol class="cif-workflow cif-workflow--modal">
                <li class="is-complete"><span class="cif-workflow__number">1</span><div><strong>Cadastro</strong><small>Dados biográficos registrados</small></div></li>
                <li class="<?= $hasBiometry ? 'is-complete' : 'is-pending' ?>"><span class="cif-workflow__number">2</span><div><strong>Biometria</strong><small><?= $hasBiometry ? 'Fotografia e assinatura válidas' : 'Pendente de coleta válida' ?></small></div></li>
                <li class="<?= $hasProcess ? 'is-complete' : 'is-pending' ?>"><span class="cif-workflow__number">3</span><div><strong>Processo</strong><small><?= $hasProcess ? 'Há processo administrativo registrado' : 'Nenhum processo iniciado' ?></small></div></li>
                <li class="<?= $hasEmission ? 'is-complete' : 'is-pending' ?>"><span class="cif-workflow__number">4</span><div><strong>Emissão</strong><small><?= $hasEmission ? 'CIF já gerada no histórico' : 'Nenhuma CIF gerada' ?></small></div></li>
                <li class="<?= $hasActiveCif ? 'is-complete' : 'is-pending' ?>"><span class="cif-workflow__number">5</span><div><strong>Entrega e ativação</strong><small><?= $hasActiveCif ? 'Existe CIF ativa para o agente' : 'Ainda não há CIF ativa' ?></small></div></li>
            </ol>
        </div>
        <div class="cif-modal__footer">
            <button type="button" class="cif-button cif-button--ghost" data-modal-close>Fechar</button>
            <?php if ($nextAction !== null): ?><a class="cif-button cif-button--primary" href="<?= esc_url($nextAction['url']) ?>"><?= esc_html($nextAction['label']) ?></a><?php endif; ?>
        </div>
    </div>
</div>

<div class="cif-modal" id="cif-agent-actions-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cif-agent-actions-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog" role="document">
        <div class="cif-modal__head"><div><span class="cif-modal__eyebrow">Ações rápidas</span><h2 id="cif-agent-actions-title"><?= esc_html((string) $agent['nome_completo']) ?></h2><p>Escolha a operação adequada para este cadastro.</p></div><button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button></div>
        <div class="cif-modal__body"><div class="cif-action-grid">
            <?php if ($canEdit): ?><a class="cif-action-tile" href="<?= esc_url(home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']) . '/editar')) ?>"><strong>Editar cadastro</strong><span>Atualizar dados biográficos e situação funcional.</span></a><?php endif; ?>
            <?php if ($canViewBiometry): ?><a class="cif-action-tile" href="<?= esc_url(home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']) . '/biometria')) ?>"><strong><?= $canCaptureBiometry ? 'Gerenciar biometria' : 'Visualizar biometria' ?></strong><span>Fotografia, assinatura e histórico de coletas.</span></a><?php endif; ?>
            <?php if ($canCreateProcess && (int) $agent['ativo'] === 1): ?><a class="cif-action-tile" href="<?= esc_url(add_query_arg('agente', (string) $agent['uuid'], home_url('/cif/processos/novo'))) ?>"><strong>Novo processo CIF</strong><span>Iniciar primeira emissão, segunda via ou outro procedimento.</span></a><?php endif; ?>
            <button class="cif-action-tile" type="button" data-modal-close data-modal-open="cif-agent-flow-modal"><strong>Ver fluxo da CIF</strong><span>Acompanhar a cronologia operacional deste agente.</span></button>
            <?php if ($canDelete): ?><button class="cif-action-tile cif-action-tile--admin" type="button" data-modal-close data-modal-open="cif-agent-governance-modal"><strong>Gestão administrativa</strong><span>Arquivar, reativar ou descartar cadastro antes da primeira emissão.</span></button><?php endif; ?>
        </div></div>
    </div>
</div>


<?php if ($canDelete): ?>
<div class="cif-modal" id="cif-agent-governance-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cif-agent-governance-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog" role="document">
        <div class="cif-modal__head"><div><span class="cif-modal__eyebrow">Administração</span><h2 id="cif-agent-governance-title">Gestão do cadastro do agente</h2><p><?= esc_html((string) $agent['nome_completo']) ?> · matrícula <?= esc_html((string) $agent['matricula']) ?></p></div><button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button></div>
        <div class="cif-modal__body">
            <div class="cif-alert cif-alert--warning">Biometria, validações e processos ainda podem ser descartados enquanto nenhuma CIF tiver sido emitida. Depois da primeira emissão, o histórico da identidade funcional torna-se permanente.</div>
            <div class="cif-admin-action-stack">
                <?php if ($canEdit): ?>
                    <form method="post" action="<?= esc_url(home_url('/cif/agentes/'.rawurlencode((string)$agent['uuid']).'/status')) ?>">
                        <input type="hidden" name="smtt_cif_nonce" value="<?= esc_attr(smtt_cif_create_nonce('smtt_cif_agent_status_'.(string)$agent['uuid'])) ?>"><input type="hidden" name="active" value="<?= (int)$agent['ativo']===1 ? '0':'1' ?>">
                        <button class="cif-button <?= (int)$agent['ativo']===1 ? 'cif-button--secondary':'cif-button--primary' ?>" type="submit"><?= (int)$agent['ativo']===1 ? 'Arquivar cadastro':'Reativar cadastro' ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($removable): ?>
                    <form method="post" action="<?= esc_url(home_url('/cif/agentes/'.rawurlencode((string)$agent['uuid']).'/excluir')) ?>" data-confirm="O cadastro e todos os vínculos preparatórios serão removidos. Tem certeza?" data-confirm-title="Descartar cadastro antes da emissão?" data-confirm-label="Sim, descartar cadastro">
                        <input type="hidden" name="smtt_cif_nonce" value="<?= esc_attr(smtt_cif_create_nonce('smtt_cif_agent_delete_'.(string)$agent['uuid'])) ?>">
                        <label class="cif-field"><span>Justificativa da exclusão</span><textarea name="delete_reason" rows="3" maxlength="500" required placeholder="Ex.: cadastro lançado incorretamente; será refeito."></textarea></label>
                        <button class="cif-button cif-button--danger" type="submit">Descartar cadastro e preparação</button>
                    </form>
                <?php else: ?>
                    <div class="cif-alert cif-alert--info">A exclusão está bloqueada porque já existe CIF emitida. O cadastro pode ser corrigido, e uma emissão incorreta deve ser invalidada/substituída sem apagar o histórico.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<section id="dados" class="cif-profile-section cif-panel">
    <div class="cif-section__head"><div><h2>Dados cadastrais</h2><p>Versão biográfica atual e situação funcional.</p></div><?php if ($canEdit): ?><a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']) . '/editar')) ?>">Editar</a><?php endif; ?></div>
    <div class="cif-detail-grid">
        <dl class="cif-definition-list">
            <div><dt>Nome completo</dt><dd><?= esc_html((string) $agent['nome_completo']) ?></dd></div>
            <div><dt>Matrícula</dt><dd><?= esc_html((string) $agent['matricula']) ?></dd></div>
            <div><dt>CPF</dt><dd><?= esc_html((string) ($canViewSensitive ? ($agent['cpf'] ?? $agent['cpf_masked']) : $agent['cpf_masked'])) ?></dd></div>
            <div><dt>Naturalidade</dt><dd><?= esc_html((string) $agent['naturalidade']) ?></dd></div>
            <div><dt>Data de nascimento</dt><dd><?= esc_html(mysql2date('d/m/Y', (string) $agent['data_nascimento'])) ?></dd></div>
            <div><dt>Tipo sanguíneo</dt><dd><?= esc_html((string) $agent['tipo_sanguineo']) ?></dd></div>
            <div><dt>Data de admissão</dt><dd><?= esc_html(mysql2date('d/m/Y', (string) $agent['data_admissao'])) ?></dd></div>
        </dl>
        <dl class="cif-definition-list">
            <div><dt>Situação funcional</dt><dd><span class="cif-badge cif-badge--success"><?= esc_html((string) $agent['situacao_funcional_label']) ?></span></dd></div>
            <div><dt>Fonte dos dados</dt><dd><?= esc_html((string) $agent['fonte']) ?><?= $agent['fonte_referencia'] ? ' · ' . esc_html((string) $agent['fonte_referencia']) : '' ?></dd></div>
            <div><dt>Versão biográfica</dt><dd>v<?= esc_html((string) $agent['dados_versao']) ?></dd></div>
            <div><dt>Cadastro no CIF</dt><dd><?= esc_html($fmt((string) $agent['criado_em'])) ?></dd></div>
            <div><dt>Última atualização</dt><dd><?= esc_html($fmt((string) $agent['atualizado_em'])) ?></dd></div>
            <div><dt>UUID interno</dt><dd><code><?= esc_html((string) $agent['uuid']) ?></code></dd></div>
        </dl>
    </div>
</section>

<?php if ($canViewBiometry): ?>
<section id="biometria" class="cif-profile-section cif-section cif-panel">
    <div class="cif-section__head"><div><h2>Biometria</h2><p>Fotografia e assinatura integradas à ficha do agente.</p></div><a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']) . '/biometria')) ?>"><?= $canCaptureBiometry ? 'Gerenciar biometria' : 'Visualizar' ?></a></div>
    <?php if ($currentBio === null): ?>
        <div class="cif-alert cif-alert--warning">Nenhuma coleta biométrica válida. Este requisito precisará ser atendido antes de determinadas emissões.</div>
    <?php else: ?>
        <div class="cif-biometry-preview-grid">
            <figure class="cif-biometry-preview"><figcaption>Fotografia atual</figcaption><?php if (!empty($currentBio['foto_arquivo_uuid'])): ?><img src="<?= esc_url(home_url('/cif/biometria/arquivo/' . rawurlencode((string) $currentBio['foto_arquivo_uuid']))) ?>" alt="Fotografia atual"><?php endif; ?><small><?= !empty($currentBio['foto_largura']) && !empty($currentBio['foto_altura']) ? esc_html((string)$currentBio['foto_largura'].'×'.(string)$currentBio['foto_altura'].' px') : 'Imagem biométrica' ?><?php if ((int)($currentBio['foto_dpi'] ?? 0) > 0): ?> · <?= esc_html((string)$currentBio['foto_dpi']) ?> DPI<?php endif; ?></small></figure>
            <figure class="cif-biometry-preview cif-biometry-preview--signature"><figcaption>Assinatura atual</figcaption><?php if (!empty($currentBio['assinatura_arquivo_uuid'])): ?><img src="<?= esc_url(home_url('/cif/biometria/arquivo/' . rawurlencode((string) $currentBio['assinatura_arquivo_uuid']))) ?>" alt="Assinatura atual"><?php endif; ?><small><?= !empty($currentBio['assinatura_largura']) && !empty($currentBio['assinatura_altura']) ? esc_html((string)$currentBio['assinatura_largura'].'×'.(string)$currentBio['assinatura_altura'].' px') : 'Imagem de assinatura' ?><?php if ((int)($currentBio['assinatura_dpi'] ?? 0) > 0): ?> · <?= esc_html((string)$currentBio['assinatura_dpi']) ?> DPI<?php endif; ?></small></figure>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section id="processos" class="cif-profile-section cif-section">
    <div class="cif-section__head"><div><h2>Processos do Agente</h2><p>Últimos processos administrativos vinculados.</p></div><?php if ($canCreateProcess && (int) $agent['ativo'] === 1): ?><a class="cif-button cif-button--primary" href="<?= esc_url(add_query_arg('agente', (string) $agent['uuid'], home_url('/cif/processos/novo'))) ?>">Novo processo</a><?php endif; ?></div>
    <div class="cif-table-wrap"><table class="cif-table"><thead><tr><th>Tipo</th><th>Status</th><th>Protocolo CIF</th><th>Criado</th><th></th></tr></thead><tbody>
    <?php if ($recentProcesses === []): ?><tr><td colspan="5" class="cif-empty">Nenhum processo vinculado.</td></tr><?php else: foreach ($recentProcesses as $row): ?><tr><td><?= esc_html(\SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessType::label((string)$row['tipo_codigo'])) ?><br><span class="cif-muted cif-small">Ciclo <?= str_pad((string)($row['ciclo_numero'] ?? 0),2,'0',STR_PAD_LEFT) ?></span></td><td><span class="cif-badge cif-process-status--<?= esc_attr(strtolower((string)$row['status_codigo'])) ?>"><?= esc_html(\SMTT\CIF\Gestao\Domain\IssuanceProcess\ProcessStatus::label((string)$row['status_codigo'])) ?></span></td><td><code><?= esc_html((string)$row['numero_protocolo']) ?></code><?php if (!empty($row['protocolo_externo'])): ?><br><span class="cif-muted cif-small">Ref. externa: <?= esc_html((string)$row['protocolo_externo']) ?></span><?php endif; ?></td><td><?= esc_html($fmt((string)$row['criado_em'])) ?></td><td class="cif-table__actions"><a class="cif-link-action" href="<?= esc_url(home_url('/cif/processos/'.rawurlencode((string)$row['uuid']))) ?>">Abrir</a></td></tr><?php endforeach; endif; ?>
    </tbody></table></div>
</section>

<section id="emissoes" class="cif-profile-section cif-section">
    <div class="cif-section__head"><div><h2>Emissões</h2><p>Histórico das carteiras emitidas para este agente.</p></div></div>
    <div class="cif-table-wrap"><table class="cif-table"><thead><tr><th>Código</th><th>Tipo</th><th>Validade</th><th>Situação física</th><th>Emissão</th><th></th></tr></thead><tbody>
    <?php if ($recentEmissions === []): ?><tr><td colspan="6" class="cif-empty">Nenhuma CIF emitida.</td></tr><?php else: foreach ($recentEmissions as $row): ?><tr><td><code><?= esc_html((string)$row['codigo_validacao']) ?></code></td><td><?= esc_html((string)$row['tipo_emissao_codigo']) ?></td><td><span class="cif-badge cif-emission-status--<?= esc_attr(strtolower((string)$row['status_validade'])) ?>"><?= esc_html((string)$row['status_validade']) ?></span></td><td><?= esc_html((string)$row['status_fisico']) ?></td><td><?= esc_html($fmt((string)$row['emitida_em'])) ?></td><td class="cif-table__actions"><a class="cif-link-action" href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$row['uuid']))) ?>">Abrir emissão</a> <a class="cif-link-action" href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$row['uuid']).'/visualizar')) ?>" target="_blank" rel="noopener">Visualizar carteira</a></td></tr><?php endforeach; endif; ?>
    </tbody></table></div>
</section>

<section id="historico" class="cif-profile-section cif-section">
    <div class="cif-section__head"><div><h2>Histórico cadastral</h2><p>Versões biográficas e mudanças da situação funcional são preservadas.</p></div></div>
    <div class="cif-detail-grid">
        <div class="cif-table-wrap"><table class="cif-table"><thead><tr><th>Versão</th><th>Registrada</th><th>Nome / matrícula</th><th>CPF</th></tr></thead><tbody><?php foreach ($agent['biographical_history'] as $row): ?><tr><td><strong>v<?= esc_html((string)$row['versao']) ?></strong></td><td><?= esc_html($fmt((string)$row['criado_em'])) ?></td><td><?= esc_html((string)$row['nome_completo']) ?><br><span class="cif-small cif-muted"><?= esc_html((string)$row['matricula_snapshot']) ?></span></td><td><?= esc_html((string)$row['cpf_masked']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="cif-table-wrap"><table class="cif-table"><thead><tr><th>Situação</th><th>Início</th><th>Fim</th></tr></thead><tbody><?php foreach ($agent['functional_history'] as $row): ?><tr><td><?= esc_html((string)$row['label']) ?></td><td><?= esc_html($fmt((string)$row['inicio_em'])) ?></td><td><?= $row['fim_em'] ? esc_html($fmt((string)$row['fim_em'])) : '<span class="cif-badge cif-badge--success">Atual</span>' ?></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
</section>

<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
