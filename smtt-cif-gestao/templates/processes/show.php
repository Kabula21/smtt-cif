<?php
/** @var array<string,mixed> $process */
/** @var array<string,string> $documentTypeOptions */
/** @var array<string,string> $validationMethodOptions */
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
$fmt = static function ($value): string {
    if (!is_string($value) || $value === '') return '—';
    return wp_date('d/m/Y H:i', strtotime($value . ' UTC'));
};
$fmtDate = static function ($value): string {
    if (!is_string($value) || $value === '') return '—';
    return wp_date('d/m/Y', strtotime($value . ' 00:00:00 UTC'));
};
$sourceLabels = [
    'CADASTRO_MANUAL_CIF' => 'Cadastro manual',
    'ALTERACAO_MANUAL_CIF' => 'Alteração manual',
    'IMPORTACAO_LOTE_CIF' => 'Importação em lote',
];
$currentBiometry = is_array($process['current_biometry'] ?? null) ? $process['current_biometry'] : null;
$photoUrl = $canViewBiometry && is_array($currentBiometry) && !empty($currentBiometry['foto_arquivo_uuid'])
    ? home_url('/cif/biometria/arquivo/' . rawurlencode((string) $currentBiometry['foto_arquivo_uuid']))
    : null;
$currentValidation = is_array($process['current_validation'] ?? null) ? $process['current_validation'] : null;

$returnToReport = null;
if (isset($_GET['retorno'])) {
    $candidate = wp_validate_redirect((string)wp_unslash($_GET['retorno']), '');
    $reportsPath = wp_parse_url(home_url('/cif/relatorios'), PHP_URL_PATH);
    $candidatePath = $candidate !== '' ? wp_parse_url($candidate, PHP_URL_PATH) : null;
    if (is_string($candidate) && $candidate !== '' && is_string($reportsPath) && $candidatePath === $reportsPath) {
        $returnToReport = $candidate;
    }
}
$status = (string) $process['status_codigo'];
$editable = in_array($status, ['RASCUNHO','EM_ANALISE','PENDENTE_DOCUMENTACAO'], true);
$terminal = in_array($status, ['CONCLUIDO','INDEFERIDO','CANCELADO'], true);
$actions = [];
if (!$terminal) {
    if ($status === 'RASCUNHO' && $canEdit) $actions[] = ['EM_ANALISE','Iniciar tramitação',false,'primary'];
    if ($status === 'EM_ANALISE' && $canEdit) $actions[] = ['PENDENTE_DOCUMENTACAO','Registrar pendência',true,'secondary'];
    if ($status === 'PENDENTE_DOCUMENTACAO' && $canEdit) $actions[] = ['EM_ANALISE','Retomar tramitação',false,'secondary'];
    if ($status === 'EM_ANALISE' && $canApprove) { if ($currentValidation !== null || $canValidate) $actions[] = ['APROVADO','Aprovar e liberar emissão',false,'primary']; $actions[] = ['INDEFERIDO','Indeferir processo',true,'danger']; }
    if (in_array($status, ['RASCUNHO','EM_ANALISE','PENDENTE_DOCUMENTACAO','APROVADO'], true) && $canCancel) $actions[] = ['CANCELADO','Cancelar processo',true,'danger'];
}
$actionTargets = array_column($actions, 0);
?>
<section class="cif-page-head"><div><p class="cif-eyebrow">Processo administrativo</p><h1><?= esc_html((string) $process['tipo_label']) ?></h1><p><strong><?= esc_html((string) $process['nome_completo']) ?></strong> · Matrícula <?= esc_html((string) $process['matricula']) ?></p></div><div class="cif-inline-actions"><?php if ($returnToReport !== null): ?><a class="cif-button cif-button--ghost" href="<?= esc_url($returnToReport) ?>">Voltar ao relatório</a><?php endif; ?><span class="cif-badge cif-process-status--<?= esc_attr(strtolower($status)) ?>"><?= esc_html((string) $process['status_label']) ?></span><?php if ($canEdit && $editable): ?><a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/processos/' . rawurlencode((string) $process['uuid']) . '/editar')) ?>">Editar</a><?php endif; ?></div></section>
<?php if ($notice): ?><div class="cif-alert cif-alert--<?= esc_attr($notice['type']) ?>"><?= esc_html($notice['text']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div><?php endif; ?>

<div class="cif-detail-grid">
<section class="cif-panel"><h2>Dados do processo</h2><dl class="cif-definition-list">
<div><dt>Identificador</dt><dd><code><?= esc_html((string) $process['uuid']) ?></code></dd></div>
<div><dt>Ciclo do agente</dt><dd><strong><?= str_pad((string)($process['ciclo_numero'] ?? 0),2,'0',STR_PAD_LEFT) ?></strong></dd></div>
<div><dt>Tipo</dt><dd><?= esc_html((string) $process['tipo_label']) ?></dd></div>
<div><dt>Status</dt><dd><?= esc_html((string) $process['status_label']) ?></dd></div>
<div><dt>Protocolo CIF</dt><dd><code><?= esc_html((string) $process['numero_protocolo']) ?></code></dd></div>
<div><dt>Protocolo externo</dt><dd><?= esc_html((string) (($process['protocolo_externo'] ?? '') ?: '—')) ?></dd></div>
<div><dt>Criado em</dt><dd><?= esc_html($fmt($process['criado_em'])) ?> por <?= esc_html((string) $process['criado_por_nome']) ?></dd></div>
<div><dt>Justificativa</dt><dd><?= nl2br(esc_html((string) ($process['justificativa'] ?: '—'))) ?></dd></div>
<?php if ($process['fato_em']): ?><div><dt>Fato</dt><dd><?= esc_html($fmt($process['fato_em'])) ?></dd></div><?php endif; ?>
<?php if ($process['comunicado_em']): ?><div><dt>Comunicação</dt><dd><?= esc_html($fmt($process['comunicado_em'])) ?></dd></div><?php endif; ?>
</dl></section>
<section class="cif-panel"><h2>Pré-requisitos para aprovação</h2><div class="cif-process-checks">
<div><strong><?= $currentValidation !== null ? '✓' : (($canApprove && $canValidate) ? '↻' : '✕') ?></strong><span><?php if ($currentValidation !== null): ?>Dados da emissão conferidos<?php elseif ($canApprove && $canValidate): ?>Conferência dos dados será concluída na aprovação<?php else: ?>Conferência dos dados ainda pendente<?php endif; ?></span></div>
<?php foreach ([
    'biometria_valida' => 'Existe fotografia e assinatura biométrica válidas',
    'bo_quando_exigido' => 'BO anexado quando exigido',
    'comunicacao_extravio' => 'Comunicação do extravio registrada quando exigida',
] as $key => $label): ?><div><strong><?= !empty($process['approval_checks'][$key]) ? '✓' : '✕' ?></strong><span><?= esc_html($label) ?></span></div><?php endforeach; ?></div>
<div class="cif-process-review-status <?= $currentValidation !== null ? 'is-complete' : 'is-pending' ?>">
    <div><span class="cif-small cif-muted">Conferência dos dados</span>
    <?php if ($currentValidation !== null): ?>
        <strong>Conferida em <?= esc_html($fmt((string) $currentValidation['validado_em'])) ?></strong>
        <small>Responsável: <?= esc_html((string) $currentValidation['registrado_por_nome']) ?> · <?= esc_html($validationMethodOptions[(string) $currentValidation['metodo']] ?? (string) $currentValidation['metodo']) ?></small>
    <?php else: ?>
        <strong>Pendente</strong>
        <small><?= ($canApprove && $canValidate) ? 'A conferência completa será apresentada dentro da ação de aprovação.' : 'Um usuário autorizado precisa registrar a conferência antes da aprovação.' ?></small>
    <?php endif; ?></div>
    <?php if ($currentValidation === null && $canValidate && !$canApprove && !$terminal): ?><button type="button" class="cif-button cif-button--secondary" data-modal-open="cif-process-validation-modal">Conferir dados agora</button><?php endif; ?>
</div>
<?php if ($status === 'PENDENTE_DOCUMENTACAO' && is_array($process['active_pending'] ?? null)): $pendingData = is_array($process['active_pending']['dados_decodificados'] ?? null) ? $process['active_pending']['dados_decodificados'] : []; ?>
<div class="cif-process-pending-summary"><div><span class="cif-small cif-muted">Pendência atual</span><strong><?= esc_html((string) ($pendingData['motivo_label'] ?? 'Pendência registrada')) ?></strong><?php if (!empty($pendingData['justificativa'])): ?><small><?= esc_html((string) $pendingData['justificativa']) ?></small><?php endif; ?></div><?php if (!empty($pendingData['prazo_regularizacao'])): ?><span class="cif-badge">Prazo: <?= esc_html($fmtDate((string) $pendingData['prazo_regularizacao'])) ?></span><?php endif; ?></div>
<?php endif; ?>
<?php if ($status === 'APROVADO'): ?>
<div class="cif-alert cif-alert--success">Processo aprovado. A emissão está liberada, desde que os requisitos técnicos de emissão estejam disponíveis. O ciclo deste agente já está reservado pelo processo.</div>
<?php if (!empty($canGenerateEmission)): ?>
<form method="post" action="<?= esc_url(home_url('/cif/processos/' . rawurlencode((string) $process['uuid']) . '/gerar-emissao')) ?>" class="cif-process-action-form" data-confirm="Gerar a emissão agora? O código e o token QR serão atribuídos definitivamente após a conclusão da transação.">
<?php smtt_cif_nonce_field('smtt_cif_emission_generate_' . (string) $process['uuid'], 'smtt_cif_nonce'); ?>
<button class="cif-button cif-button--primary" type="submit">Gerar emissão CIF</button>
</form>
<?php endif; ?>
<?php endif; ?>
</section>
</div>

<section class="cif-section cif-panel"><div class="cif-section__head"><div><h2>Documentos do processo</h2><p>Arquivos são armazenados criptografados fora da Biblioteca de Mídia do WordPress.</p></div><?php if ($canAttach && !$terminal): ?><button type="button" class="cif-button cif-button--secondary" data-modal-open="cif-process-document-modal">Anexar documento</button><?php endif; ?></div>
<div class="cif-table-wrap"><table class="cif-table"><thead><tr><th>Tipo</th><th>Documento</th><th>Incluído</th><th></th></tr></thead><tbody><?php if ($process['documents'] === []): ?><tr><td colspan="4" class="cif-empty">Nenhum documento anexado.</td></tr><?php else: foreach ($process['documents'] as $doc): ?><tr><td><?= esc_html($documentTypeOptions[(string) $doc['tipo_documento']] ?? (string) $doc['tipo_documento']) ?></td><td><strong><?= esc_html((string) $doc['nome_original']) ?></strong><br><span class="cif-muted cif-small"><?= esc_html((string) ($doc['numero_documento'] ?: 'Sem número')) ?></span></td><td><?= esc_html($fmt($doc['incluido_em'])) ?><br><span class="cif-muted cif-small"><?= esc_html((string) $doc['incluido_por_nome']) ?></span></td><td class="cif-table__actions"><a class="cif-link-action" target="_blank" rel="noopener" href="<?= esc_url(home_url('/cif/processos/arquivo/' . rawurlencode((string) $doc['arquivo_uuid']))) ?>">Abrir</a></td></tr><?php endforeach; endif; ?></tbody></table></div>
</section>

<?php if (!$terminal): ?>
<section class="cif-section cif-panel"><div class="cif-section__head"><div><h2>Tramitação</h2><p>Aprovação é a ação principal. Pendência, indeferimento e cancelamento ficam como decisões administrativas secundárias.</p></div><?php if ($actions !== []): ?><button type="button" class="cif-button cif-button--primary" data-modal-open="cif-process-transition-modal">Analisar processo</button><?php endif; ?></div><?php if ($actions === []): ?><p class="cif-muted">Nenhuma ação manual disponível neste estado. A progressão para execução/conclusão será feita pelo módulo de emissão.</p><?php endif; ?></section>
<?php endif; ?>

<?php if ($canValidate && !$canApprove && !$terminal && $currentValidation === null): ?>
<div class="cif-modal" id="cif-process-validation-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cif-process-validation-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog cif-modal__dialog--wide" role="document">
        <div class="cif-modal__head"><div><span class="cif-modal__eyebrow">Conferência da emissão</span><h2 id="cif-process-validation-title">Conferir dados que serão usados na CIF</h2><p>Revise os dados cadastrais e a biometria atual antes de registrar a conferência.</p></div><button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button></div>
        <div class="cif-modal__body">
            <div class="cif-process-review">
                <div class="cif-process-review__photo"><?php if ($photoUrl !== null): ?><img src="<?= esc_url($photoUrl) ?>" alt="Fotografia biométrica atual de <?= esc_attr((string) $process['nome_completo']) ?>"><?php else: ?><div class="cif-process-review__photo-empty">Foto protegida<br><small>ou indisponível</small></div><?php endif; ?></div>
                <div class="cif-process-review__data">
                    <div><span>Nome completo</span><strong><?= esc_html((string) $process['nome_completo']) ?></strong></div>
                    <div><span>Matrícula</span><strong><?= esc_html((string) ($process['matricula_biografica'] ?? $process['matricula'])) ?></strong></div>
                    <div><span>CPF</span><strong><?= $process['cpf_display'] ? esc_html((string) $process['cpf_display']) : 'Protegido' ?></strong></div>
                    <div><span>Situação funcional</span><strong><?= esc_html((string) $process['situacao_funcional_atual']) ?></strong></div>
                    <div><span>Naturalidade</span><strong><?= esc_html((string) $process['naturalidade']) ?></strong></div>
                    <div><span>Data de nascimento</span><strong><?= esc_html($fmtDate((string) $process['data_nascimento'])) ?></strong></div>
                    <div><span>Tipo sanguíneo</span><strong><?= esc_html((string) (($process['tipo_sanguineo'] ?? '') ?: '—')) ?></strong></div>
                    <div><span>Data de admissão</span><strong><?= esc_html($fmtDate((string) ($process['data_admissao'] ?? ''))) ?></strong></div>
                    <div><span>Versão cadastral</span><strong>v<?= esc_html((string) $process['dados_versao']) ?></strong></div>
                    <div><span>Origem do cadastro</span><strong><?= esc_html($sourceLabels[(string) ($process['fonte'] ?? '')] ?? (string) (($process['fonte'] ?? '') ?: '—')) ?></strong></div>
                    <div class="cif-process-review__span"><span>Biometria atual</span><strong><?= $currentBiometry ? 'Coletada em ' . esc_html($fmt((string) $currentBiometry['coletada_em'])) . ' por ' . esc_html((string) $currentBiometry['operador_nome']) : 'Nenhuma biometria válida localizada' ?></strong></div>
                </div>
            </div>
            <form method="post" action="<?= esc_url(home_url('/cif/processos/' . rawurlencode((string) $process['uuid']) . '/validar-dados')) ?>" class="cif-form cif-process-review-form">
                <?php smtt_cif_nonce_field('smtt_cif_process_validate_' . (string) $process['uuid'], 'smtt_cif_nonce'); ?>
                <label class="cif-field"><span>Método de conferência</span><select name="metodo" required><?php foreach ($validationMethodOptions as $code => $label): ?><option value="<?= esc_attr($code) ?>"><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
                <label class="cif-checkbox"><input type="checkbox" name="titular_confirmou" value="1" required><span><strong>Confirmo que os dados apresentados correspondem ao cadastro e foram conferidos com o titular.</strong><small>Qualquer alteração cadastral posterior exigirá nova conferência.</small></span></label>
                <label class="cif-field"><span>Observações da conferência</span><textarea name="observacoes" rows="3" maxlength="1000" placeholder="Opcional"></textarea></label>
                <div class="cif-form-actions"><button type="button" class="cif-button cif-button--ghost" data-modal-close>Cancelar</button><button class="cif-button cif-button--primary" type="submit">Registrar conferência</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canAttach && !$terminal): ?>
<div class="cif-modal" id="cif-process-document-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cif-process-document-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog" role="document">
        <div class="cif-modal__head"><div><span class="cif-modal__eyebrow">Documentação</span><h2 id="cif-process-document-title">Anexar documento</h2><p>PDF, JPEG ou PNG, até 10 MB, com armazenamento privado criptografado.</p></div><button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button></div>
        <div class="cif-modal__body">
            <form method="post" enctype="multipart/form-data" action="<?= esc_url(home_url('/cif/processos/' . rawurlencode((string) $process['uuid']) . '/documentos')) ?>" class="cif-form">
                <?php smtt_cif_nonce_field('smtt_cif_process_document_' . (string) $process['uuid'], 'smtt_cif_nonce'); ?>
                <div class="cif-form-grid"><label class="cif-field"><span>Tipo</span><select name="tipo_documento" required><?php foreach ($documentTypeOptions as $code => $label): ?><option value="<?= esc_attr($code) ?>"><?= esc_html($label) ?></option><?php endforeach; ?></select></label><label class="cif-field"><span>Número do documento</span><input type="text" name="numero_documento" maxlength="100"></label><label class="cif-field"><span>Data do documento</span><input type="date" name="emitido_em"></label><label class="cif-field"><span>Arquivo</span><input type="file" name="documento" accept="application/pdf,image/jpeg,image/png" required></label></div>
                <div class="cif-form-actions"><button type="button" class="cif-button cif-button--ghost" data-modal-close>Cancelar</button><button class="cif-button cif-button--primary" type="submit">Anexar documento</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$terminal && $actions !== []): ?>
<div class="cif-modal" id="cif-process-transition-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cif-process-transition-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog cif-modal__dialog--wide" role="document">
        <div class="cif-modal__head"><div><span class="cif-modal__eyebrow">Tramitação</span><h2 id="cif-process-transition-title">Analisar processo</h2><p>Confira os dados para aprovar. Use as outras decisões apenas quando houver pendência, impedimento ou cancelamento administrativo.</p></div><button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button></div>
        <div class="cif-modal__body">
            <?php if (in_array('APROVADO', $actionTargets, true)): ?>
            <form method="post" action="<?= esc_url(home_url('/cif/processos/' . rawurlencode((string) $process['uuid']) . '/transicao')) ?>" class="cif-process-decision-primary">
                <?php smtt_cif_nonce_field('smtt_cif_process_transition_' . (string) $process['uuid'], 'smtt_cif_nonce'); ?><input type="hidden" name="target_status" value="APROVADO"><input type="hidden" name="lock_version" value="<?= esc_attr((string) $process['lock_version']) ?>">
                <div class="cif-process-decision-primary__head"><div><span class="cif-modal__eyebrow">Ação principal</span><h3>Aprovar e liberar emissão</h3><p>Estes são os dados que servirão de base para a nova CIF. Confira antes de aprovar.</p></div><span class="cif-badge">Ciclo <?= esc_html(str_pad((string)($process['ciclo_numero'] ?? 0), 2, '0', STR_PAD_LEFT)) ?></span></div>
                <div class="cif-process-review">
                    <div class="cif-process-review__photo"><?php if ($photoUrl !== null): ?><img src="<?= esc_url($photoUrl) ?>" alt="Fotografia biométrica atual de <?= esc_attr((string) $process['nome_completo']) ?>"><?php else: ?><div class="cif-process-review__photo-empty">Foto protegida<br><small>ou indisponível</small></div><?php endif; ?></div>
                    <div class="cif-process-review__data">
                        <div><span>Nome completo</span><strong><?= esc_html((string) $process['nome_completo']) ?></strong></div>
                        <div><span>Matrícula</span><strong><?= esc_html((string) ($process['matricula_biografica'] ?? $process['matricula'])) ?></strong></div>
                        <div><span>CPF</span><strong><?= $process['cpf_display'] ? esc_html((string) $process['cpf_display']) : 'Protegido' ?></strong></div>
                        <div><span>Situação funcional</span><strong><?= esc_html((string) $process['situacao_funcional_atual']) ?></strong></div>
                        <div><span>Naturalidade</span><strong><?= esc_html((string) $process['naturalidade']) ?></strong></div>
                        <div><span>Data de nascimento</span><strong><?= esc_html($fmtDate((string) $process['data_nascimento'])) ?></strong></div>
                        <div><span>Tipo sanguíneo</span><strong><?= esc_html((string) (($process['tipo_sanguineo'] ?? '') ?: '—')) ?></strong></div>
                        <div><span>Data de admissão</span><strong><?= esc_html($fmtDate((string) ($process['data_admissao'] ?? ''))) ?></strong></div>
                        <div><span>Versão cadastral</span><strong>v<?= esc_html((string) $process['dados_versao']) ?></strong></div>
                        <div><span>Origem do cadastro</span><strong><?= esc_html($sourceLabels[(string) ($process['fonte'] ?? '')] ?? (string) (($process['fonte'] ?? '') ?: '—')) ?></strong></div>
                        <div class="cif-process-review__span"><span>Biometria atual</span><strong><?= $currentBiometry ? 'Coletada em ' . esc_html($fmt((string) $currentBiometry['coletada_em'])) . ' por ' . esc_html((string) $currentBiometry['operador_nome']) : 'Nenhuma biometria válida localizada' ?></strong></div>
                    </div>
                </div>
                <?php if ($currentValidation === null): ?>
                <div class="cif-process-confirmation-box">
                    <label class="cif-field"><span>Método de conferência</span><select name="metodo_validacao" required><?php foreach ($validationMethodOptions as $code => $methodLabel): ?><option value="<?= esc_attr($code) ?>"><?= esc_html($methodLabel) ?></option><?php endforeach; ?></select></label>
                    <label class="cif-field"><span>Observações da conferência</span><textarea name="observacoes_validacao" rows="2" maxlength="1000" placeholder="Opcional"></textarea></label>
                    <label class="cif-checkbox"><input type="checkbox" name="titular_confirmou" value="1" required><span><strong>Confirmo que os dados apresentados correspondem ao cadastro e estão corretos para esta emissão.</strong><small>A conferência será registrada com meu usuário, data e hora.</small></span></label>
                </div>
                <?php else: ?>
                <div class="cif-alert cif-alert--success">Conferência já registrada em <?= esc_html($fmt((string) $currentValidation['validado_em'])) ?> por <?= esc_html((string) $currentValidation['registrado_por_nome']) ?>.</div>
                <?php endif; ?>
                <div class="cif-process-decision-primary__footer"><span class="cif-muted cif-small">A aprovação será auditada e liberará a geração da CIF quando os demais pré-requisitos estiverem atendidos.</span><button class="cif-button cif-button--primary" type="submit">Aprovar e liberar emissão</button></div>
            </form>
            <?php endif; ?>

            <?php $secondaryActions = array_values(array_filter($actions, static fn(array $action): bool => (string) $action[0] !== 'APROVADO')); if ($secondaryActions !== []): ?>
            <div class="cif-process-secondary-decisions"><div class="cif-process-secondary-decisions__head"><h3>Outras decisões</h3><p>Use somente quando o processo não puder seguir diretamente para aprovação.</p></div><div class="cif-process-action-modal-grid">
                <?php foreach ($secondaryActions as [$target,$label,$needReason,$style]): ?>
                <form method="post" action="<?= esc_url(home_url('/cif/processos/' . rawurlencode((string) $process['uuid']) . '/transicao')) ?>" class="cif-process-action-modal-card <?= in_array($target, ['INDEFERIDO','CANCELADO'], true) ? 'is-exception' : '' ?>">
                    <?php smtt_cif_nonce_field('smtt_cif_process_transition_' . (string) $process['uuid'], 'smtt_cif_nonce'); ?><input type="hidden" name="target_status" value="<?= esc_attr($target) ?>"><input type="hidden" name="lock_version" value="<?= esc_attr((string) $process['lock_version']) ?>">
                    <strong><?= esc_html($label) ?></strong>
                    <?php if ($target === 'PENDENTE_DOCUMENTACAO'): ?>
                        <p class="cif-muted cif-small">Interrompe temporariamente a tramitação até a regularização.</p>
                        <label class="cif-field"><span>Tipo de pendência</span><select name="motivo_codigo" required><option value="">Selecione</option><?php foreach ($pendingReasonOptions as $code => $optionLabel): ?><option value="<?= esc_attr($code) ?>"><?= esc_html($optionLabel) ?></option><?php endforeach; ?></select></label>
                        <label class="cif-field"><span>Descrição da pendência</span><textarea name="justificativa_transicao" rows="3" minlength="10" maxlength="1000" required placeholder="Explique o que precisa ser regularizado"></textarea></label>
                        <label class="cif-field"><span>Prazo para regularização</span><input type="date" name="prazo_regularizacao"><small>Opcional.</small></label>
                    <?php elseif ($target === 'INDEFERIDO'): ?>
                        <p class="cif-muted cif-small">Encerra o processo por decisão administrativa. Não use para falta temporária de documento.</p>
                        <label class="cif-field"><span>Motivo do indeferimento</span><select name="motivo_codigo" required><option value="">Selecione</option><?php foreach ($denialReasonOptions as $code => $optionLabel): ?><option value="<?= esc_attr($code) ?>"><?= esc_html($optionLabel) ?></option><?php endforeach; ?></select></label>
                        <label class="cif-field"><span>Fundamentação</span><textarea name="justificativa_transicao" rows="3" minlength="10" maxlength="1000" required placeholder="Registre a fundamentação da decisão"></textarea></label>
                    <?php elseif ($target === 'CANCELADO'): ?>
                        <p class="cif-muted cif-small">Use para duplicidade, abertura indevida, agente incorreto ou desistência.</p>
                        <label class="cif-field"><span>Motivo do cancelamento</span><select name="motivo_codigo" required><option value="">Selecione</option><?php foreach ($cancellationReasonOptions as $code => $optionLabel): ?><option value="<?= esc_attr($code) ?>"><?= esc_html($optionLabel) ?></option><?php endforeach; ?></select></label>
                        <label class="cif-field"><span>Justificativa</span><textarea name="justificativa_transicao" rows="3" minlength="10" maxlength="1000" required placeholder="Explique o cancelamento"></textarea></label>
                    <?php else: ?>
                        <p class="cif-muted cif-small"><?= $target === 'EM_ANALISE' && $status === 'PENDENTE_DOCUMENTACAO' ? 'Use quando a pendência tiver sido regularizada e o processo puder continuar.' : 'Atualiza a etapa administrativa do processo.' ?></p>
                        <?php if ($needReason): ?><label class="cif-field"><span>Justificativa</span><textarea name="justificativa_transicao" rows="3" minlength="10" maxlength="1000" required></textarea></label><?php endif; ?>
                    <?php endif; ?>
                    <button class="cif-button cif-button--<?= esc_attr($style) ?>" type="submit"><?= esc_html($label) ?></button>
                </form>
                <?php endforeach; ?>
            </div></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<section class="cif-section cif-panel cif-history-launcher"><div class="cif-section__head"><div><h2>Histórico do processo</h2><p><?= esc_html((string) count($process['events'])) ?> evento(s) preservado(s) em ordem cronológica.</p></div><button type="button" class="cif-button cif-button--secondary" data-modal-open="cif-process-history-modal">Ver linha do tempo</button></div></section>

<div class="cif-modal" id="cif-process-history-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cif-process-history-title">
<div class="cif-modal__backdrop" data-modal-close></div><div class="cif-modal__dialog cif-modal__dialog--wide" role="document"><div class="cif-modal__head"><div><span class="cif-modal__eyebrow">Linha do tempo</span><h2 id="cif-process-history-title">Histórico do processo</h2><p><?= esc_html((string) $process['tipo_label']) ?> · <?= esc_html((string) $process['nome_completo']) ?></p></div><button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button></div><div class="cif-modal__body"><div class="cif-process-timeline cif-process-timeline--modal"><?php if ($process['events'] === []): ?><p class="cif-muted">Sem eventos registrados.</p><?php else: foreach ($process['events'] as $event): ?><article><div><strong><?= esc_html((string) $event['tipo_evento']) ?></strong><span><?= esc_html($fmt($event['ocorrido_em'])) ?></span></div><p><?= esc_html((string) ($event['usuario_nome'] ?: 'Sistema')) ?><?php if ($event['estado_anterior'] || $event['estado_novo']): ?> · <?= esc_html((string) ($event['estado_anterior'] ?: '—')) ?> → <?= esc_html((string) ($event['estado_novo'] ?: '—')) ?><?php endif; ?></p><?php if (is_array($event['dados_decodificados'] ?? null) && !empty($event['dados_decodificados']['justificativa'])): ?><p><strong>Justificativa:</strong> <?= esc_html((string) $event['dados_decodificados']['justificativa']) ?></p><?php endif; ?></article><?php endforeach; endif; ?></div></div><div class="cif-modal__footer"><button class="cif-button cif-button--ghost" type="button" data-modal-close>Fechar</button></div></div></div>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
