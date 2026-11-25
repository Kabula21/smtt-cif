<?php
/** @var array<string,mixed> $emission */
/** @var string|null $qrToken */
/** @var array<string,mixed> $artifactSummary */
/** @var bool $canPrint */
/** @var bool $canViewQr */
/** @var bool $canDeliver */
/** @var array<string,mixed>|null $signedTerm */
/** @var bool $canRegisterOccurrence */
/** @var bool $canRegisterRecollection */
/** @var bool $canCancel */
/** @var array<string,string> $cancellationReasons */
/** @var array<string,string> $deliveryMethods */
/** @var array{type:string,text:string}|null $notice */
/** @var string|null $error */
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
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
$artifact = is_array($artifactSummary['artifact'] ?? null) ? $artifactSummary['artifact'] : null;
$prints = is_array($artifactSummary['prints'] ?? null) ? $artifactSummary['prints'] : [];
$qrUrl = $canViewQr && is_string($artifactSummary['qr_url'] ?? null) ? $artifactSummary['qr_url'] : null;
$openPrintAttempt = null;
foreach ($prints as $printRow) {
    if ((string)($printRow['status'] ?? '') === 'ABERTA') {
        $openPrintAttempt = $printRow;
        break;
    }
}
$isProduced = (string)$emission['status_fisico'] !== 'NAO_PRODUZIDA';
$hasDelivery = is_array($emission['delivery'] ?? null);
$canStartProduction = $canPrint
    && (string)$emission['status_fisico'] === 'NAO_PRODUZIDA'
    && !in_array((string)$emission['status_validade'], ['SUSPENSA','ENCERRADA'], true);
?>
<section class="cif-page-head"><div><p class="cif-eyebrow">Emissão imutável</p><h1><code><?= esc_html((string)$emission['codigo_validacao']) ?></code></h1><p><strong><?= esc_html((string)$emission['nome_completo']) ?></strong> · Matrícula <?= esc_html((string)$emission['matricula_snapshot']) ?></p></div><div class="cif-inline-actions"><?php if ($returnToReport !== null): ?><a class="cif-button cif-button--ghost" href="<?= esc_url($returnToReport) ?>">Voltar ao relatório</a><?php endif; ?><span class="cif-badge cif-emission-status--<?= esc_attr(strtolower((string)$emission['status_validade'])) ?>"><?= esc_html((string)$emission['status_validade_label']) ?></span></div></section>
<?php if (isset($_GET['notice']) && (string)$_GET['notice']==='generated'): ?><div class="cif-alert cif-alert--success">Emissão gerada com sucesso. Código, token QR, snapshot, biometria e numeração foram gravados na mesma transação.</div><?php endif; ?>
<?php if ($notice): ?><div class="cif-alert cif-alert--<?= esc_attr($notice['type']) ?>"><?= esc_html($notice['text']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div><?php endif; ?>
<div data-cif-production-watch data-emission-uuid="<?= esc_attr((string)$emission['uuid']) ?>"></div>
<div class="cif-emission-progress" aria-label="Etapas da emissão">
    <div class="cif-emission-progress__step is-complete"><span>1</span><div><strong>Emissão gerada</strong><small>Código e QR reservados</small></div></div>
    <div class="cif-emission-progress__step <?= $isProduced ? 'is-complete' : 'is-current' ?>"><span>2</span><div><strong>Carteira produzida</strong><small><?= $isProduced ? 'Produção registrada' : 'Aguardando produção' ?></small></div></div>
    <div class="cif-emission-progress__step <?= $hasDelivery ? 'is-complete' : (!$isProduced ? '' : 'is-current') ?>"><span>3</span><div><strong>Entregue e ativada</strong><small><?= $hasDelivery ? 'Entrega registrada' : 'Aguardando entrega' ?></small></div></div>
</div>
<div class="cif-detail-grid">
<section class="cif-panel"><h2>Identificação da emissão</h2><dl class="cif-definition-list">
<div><dt>UUID</dt><dd><code><?= esc_html((string)$emission['uuid']) ?></code></dd></div><div><dt>Código de validação</dt><dd><strong><code><?= esc_html((string)$emission['codigo_validacao']) ?></code></strong></dd></div><div><dt>Ciclo</dt><dd><?= str_pad((string)$emission['ciclo_numero'],2,'0',STR_PAD_LEFT) ?></dd></div><div><dt>Sequencial global</dt><dd><?= str_pad((string)$emission['sequencial_sistema'],4,'0',STR_PAD_LEFT) ?></dd></div><div><dt>Tipo</dt><dd><?= esc_html((string)$emission['tipo_label']) ?></dd></div><div><dt>Emitida em</dt><dd><?= esc_html($fmt($emission['emitida_em'])) ?> por <?= esc_html((string)$emission['emitida_por_nome']) ?></dd></div><div><dt>Processo</dt><dd><a href="<?= esc_url(home_url('/cif/processos/'.rawurlencode((string)$emission['processo_uuid']))) ?>"><code><?= esc_html((string)$emission['processo_uuid']) ?></code></a></dd></div></dl></section>
<section class="cif-panel"><h2>Estado atual</h2><dl class="cif-definition-list"><div><dt>Validade</dt><dd><?= esc_html((string)$emission['status_validade_label']) ?></dd></div><div><dt>Situação física</dt><dd><?= esc_html((string)$emission['status_fisico_label']) ?></dd></div><div><dt>Versão pública</dt><dd><?= esc_html((string)$emission['public_version']) ?></dd></div><div><dt>Template da emissão</dt><dd><?= esc_html((string)$emission['template_version']) ?></dd></div></dl><?php if ((string)$emission['status_validade']==='PENDENTE_ATIVACAO'): ?><div class="cif-alert cif-alert--warning">A emissão ainda não possui validade ativa. Depois da produção física, a entrega ao titular ativa esta CIF e, quando houver uma emissão anterior, trata a substituição na mesma transação.</div><?php elseif ((string)$emission['status_validade']==='ATIVA'): ?><div class="cif-alert cif-alert--success">Esta é a emissão ativa atualmente reconhecida pelo Sistema CIF.</div><?php endif; ?></section>
</div>

<section class="cif-section cif-panel">
    <div class="cif-section__head">
        <div><h2>Carteira desta emissão</h2><p>Consulte novamente a arte correspondente a esta emissão. A visualização não altera status e não registra nova produção.</p></div>
        <a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/visualizar')) ?>" target="_blank" rel="noopener">Visualizar carteira</a>
    </div>
</section>

<?php if ((string)$emission['status_validade']==='PENDENTE_ATIVACAO'): ?>
<section class="cif-section cif-panel cif-production-panel">
    <div class="cif-section__head"><div><h2>Produção da carteira</h2><p>A visualização e a impressão abrem em outra aba; esta tela permanece como centro do fluxo.</p></div></div>
    <?php if ((string)$emission['status_fisico']==='NAO_PRODUZIDA'): ?>
        <?php if (!$canPrint): ?>
            <div class="cif-alert">Seu perfil não possui permissão para produzir a carteira física.</div>
        <?php elseif (is_array($openPrintAttempt)): ?>
            <div class="cif-alert cif-alert--warning"><strong>Produção aguardando confirmação.</strong> Se ainda precisar imprimir ou salvar o PDF, reabra a carteira. Quando a produção física estiver correta, confirme aqui para avançar à entrega.</div>
            <div class="cif-form-actions">
                <a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/impressao/'.(int)$openPrintAttempt['id'])) ?>" target="_blank" rel="noopener">Reabrir carteira</a>
                <form method="post" action="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/impressao/'.(int)$openPrintAttempt['id'].'/resultado')) ?>" class="cif-inline-form">
                    <?php smtt_cif_nonce_field('smtt_cif_print_finish_'.(string)$emission['uuid'].'_'.(int)$openPrintAttempt['id'],'smtt_cif_nonce'); ?>
                    <input type="hidden" name="resultado" value="CONCLUIDA">
                    <button class="cif-button cif-button--primary" type="submit">Confirmar produção concluída</button>
                </form>
            </div>
            <small class="cif-muted">Se você apenas voltar da tela de impressão sem confirmar, nada muda e esta etapa continuará disponível.</small>
        <?php elseif ($canStartProduction): ?>
            <form method="post" target="_blank" action="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/imprimir')) ?>" class="cif-form" data-cif-production-form>
                <?php smtt_cif_nonce_field('smtt_cif_print_start_'.(string)$emission['uuid'],'smtt_cif_nonce'); ?>
                <div class="cif-form-actions">
                    <button class="cif-button cif-button--primary" type="submit">Produzir carteira</button>
                </div>
                <small class="cif-muted">A nova aba permitirá imprimir/salvar o PDF e confirmar a produção. Nenhuma ativação ocorre nessa aba.</small>
            </form>
        <?php endif; ?>
    <?php elseif ((string)$emission['status_fisico']==='EM_GUARDA_SMTT'): ?>
        <div class="cif-alert cif-alert--success"><strong>Carteira produzida.</strong> Etapa concluída. Continue abaixo para <strong>Entrega e ativação</strong>.</div>
    <?php else: ?>
        <div class="cif-alert">Situação física atual: <strong><?= esc_html((string)$emission['status_fisico_label']) ?></strong>.</div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ((string)$emission['status_validade']==='PENDENTE_ATIVACAO'): ?>
<section class="cif-section cif-panel"><h2>Termo de entrega</h2>
    <div class="cif-section__head">
        <div><p>Imprima o termo institucional já preenchido com os dados da emissão. Colha as assinaturas do agente recebedor e do responsável RH-SMTT, digitalize o documento e anexe-o abaixo.</p></div>
        <?php if ($canDeliver): ?>
            <a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/termo-entrega')) ?>" target="_blank" rel="noopener">Imprimir termo de entrega</a>
        <?php endif; ?>
    </div>
    <?php if ((string)$emission['status_fisico'] === 'EM_GUARDA_SMTT' && $canDeliver): ?>
        <?php if (is_array($signedTerm)): ?>
            <div class="cif-alert cif-alert--success"><strong>Termo assinado recebido e conferido.</strong>
                <a href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/termo-assinado/'.rawurlencode((string)$signedTerm['arquivo_uuid']))) ?>">Baixar termo anexado</a>
                · <?= esc_html($fmt($signedTerm['anexado_em'])) ?> · <?= esc_html((string)$signedTerm['anexado_por_nome']) ?>.
                Se a digitalização precisar ser corrigida, anexe outra antes de confirmar a entrega; as versões anteriores permanecerão auditadas.
            </div>
        <?php else: ?>
            <div class="cif-alert cif-alert--warning"><strong>Termo assinado pendente.</strong> A CIF não poderá ser ativada até que o RH anexe o documento com as duas assinaturas.</div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/termo-assinado/anexar')) ?>" class="cif-form">
            <?php smtt_cif_nonce_field('smtt_cif_signed_term_'.(string)$emission['uuid'], 'smtt_cif_nonce'); ?>
            <label class="cif-field"><span><?= is_array($signedTerm) ? 'Substituir digitalização do termo assinado' : 'Anexar termo assinado' ?> (PDF, JPG ou PNG; até 10 MB)</span><input type="file" name="termo_assinado" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></label>
            <label class="cif-checkbox"><input type="checkbox" name="assinaturas_conferidas" value="1" required> <span>Confirmo que conferi no documento digitalizado as assinaturas do agente recebedor e do responsável RH-SMTT.</span></label>
            <button class="cif-button cif-button--secondary" type="submit"><?= is_array($signedTerm) ? 'Substituir termo assinado' : 'Anexar e conferir termo assinado' ?></button>
        </form>
    <?php endif; ?>
</section>
<section class="cif-section cif-panel"><h2>Entrega e ativação</h2>
<?php if ((string)$emission['status_fisico']!=='EM_GUARDA_SMTT'): ?>
    <div class="cif-alert cif-alert--warning">Antes da entrega é necessário concluir a produção da carteira. A situação física precisa estar em <strong>Em guarda na SMTT</strong>.</div>
<?php elseif (!$canDeliver): ?>
    <div class="cif-alert">Seu perfil não possui simultaneamente as permissões de entrega e ativação da CIF.</div>
<?php else: ?>
    <?php $previous = is_array($emission['previous_emission'] ?? null) ? $emission['previous_emission'] : null; ?>
    <?php if ($previous): ?>
        <div class="cif-alert cif-alert--warning"><strong>Substituição:</strong> esta emissão está vinculada à CIF anterior <code><?= esc_html((string)$previous['codigo_validacao']) ?></code>, atualmente em <?= esc_html((string)$previous['status_validade']) ?> / <?= esc_html((string)$previous['status_fisico']) ?>.</div>
    <?php endif; ?>
    <form method="post" action="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/entregar')) ?>" class="cif-form">
        <?php smtt_cif_nonce_field('smtt_cif_emission_deliver_'.(string)$emission['uuid'],'smtt_cif_nonce'); ?>
        <div class="cif-alert"><strong>Meio de confirmação:</strong> Termo de entrega assinado e conferido pelo RH-SMTT.</div>
        <label class="cif-field"><span>Referência do termo/protocolo (opcional)</span><input type="text" name="referencia" maxlength="200"></label>
        <label class="cif-field"><span>Observações (opcional)</span><textarea name="observacoes" rows="3" maxlength="2000"></textarea></label>
        <label class="cif-checkbox"><input type="checkbox" name="titular_confirmou" value="1" required> <span>Confirmo que a CIF foi efetivamente entregue ao titular.</span></label>
        <?php if (!empty($emission['requires_previous_collection'])): ?>
            <label class="cif-checkbox"><input type="checkbox" name="anterior_recolhida" value="1" required> <span>Confirmo que a CIF anterior foi recolhida no ato da substituição.</span></label>
        <?php elseif (!empty($emission['is_extravio']) && $previous): ?>
            <div class="cif-alert">Como o processo decorre de perda, furto ou roubo, o recolhimento da via anterior não é exigido nesta entrega. A emissão anterior será encerrada e permanecerá registrada como não localizada quando aplicável.</div>
        <?php endif; ?>
        <div class="cif-alert cif-alert--warning"><strong>Atenção:</strong> esta operação é transacional. Ao confirmar, a nova CIF passa a <strong>ATIVA / ENTREGUE</strong>. Se houver via anterior substituível, ela será encerrada no mesmo commit.</div>
        <?php if (!is_array($signedTerm)): ?><div class="cif-alert cif-alert--warning">Anexe o termo assinado na seção acima para liberar esta etapa.</div><?php endif; ?>
        <button class="cif-button cif-button--primary" type="submit" <?= is_array($signedTerm) ? '' : 'disabled aria-disabled="true"' ?>>Registrar entrega e ativar CIF</button>
    </form>
<?php endif; ?>
</section>
<?php elseif (is_array($emission['delivery'] ?? null)): $delivery=$emission['delivery']; ?>
<section class="cif-section cif-panel"><h2>Entrega registrada</h2><dl class="cif-definition-list">
<div><dt>Entregue em</dt><dd><?= esc_html($fmt($delivery['entregue_em'])) ?></dd></div>
<div><dt>Registrado por</dt><dd><?= esc_html((string)$delivery['entregue_por_nome']) ?></dd></div>
<div><dt>Meio de confirmação</dt><dd><?= esc_html((string)$delivery['metodo_confirmacao']) ?></dd></div>
<div><dt>Referência</dt><dd><?= esc_html((string)($delivery['referencia'] ?: '—')) ?></dd></div>
<?php if (is_array($signedTerm) && $canDeliver): ?><div><dt>Termo assinado</dt><dd><a href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/termo-assinado/'.rawurlencode((string)$signedTerm['arquivo_uuid']))) ?>">Baixar comprovante arquivado</a> · <?= esc_html($fmt($signedTerm['anexado_em'])) ?></dd></div><?php endif; ?>
<?php if (!empty($delivery['codigo_anterior'])): ?><div><dt>CIF anterior</dt><dd><code><?= esc_html((string)$delivery['codigo_anterior']) ?></code><?= !empty($delivery['anterior_recolhida']) ? ' · recolhida' : '' ?></dd></div><?php endif; ?>
</dl></section>
<?php endif; ?>


<?php if ($canCancel && (string)$emission['status_validade']==='PENDENTE_ATIVACAO' && in_array((string)$emission['status_fisico'], ['NAO_PRODUZIDA','EM_GUARDA_SMTT'], true)): ?>
<section class="cif-section cif-panel cif-danger-zone">
    <div class="cif-section__head"><div><h2>Cancelamento administrativo</h2><p>Use somente quando a emissão ainda não foi ativada. O registro, código, QR e histórico não são apagados.</p></div><button type="button" class="cif-button cif-button--danger" data-modal-open="cif-emission-cancel-modal">Cancelar emissão</button></div>
</section>
<div class="cif-modal" id="cif-emission-cancel-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cif-emission-cancel-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog" role="document">
        <div class="cif-modal__head"><div><span class="cif-modal__eyebrow">Operação irreversível</span><h2 id="cif-emission-cancel-title">Cancelar emissão pendente</h2><p><?= esc_html((string)$emission['codigo_validacao']) ?> · <?= esc_html((string)$emission['nome_completo']) ?></p></div><button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button></div>
        <div class="cif-modal__body">
            <div class="cif-alert cif-alert--warning">O cancelamento encerra esta emissão e impede sua ativação futura. Se a carteira física já estiver em guarda na SMTT, ela será marcada como inutilizada. Nada é excluído do histórico.</div>
            <form method="post" action="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/cancelar')) ?>" class="cif-form">
                <?php smtt_cif_nonce_field('smtt_cif_emission_cancel_'.(string)$emission['uuid'],'smtt_cif_nonce'); ?>
                <label class="cif-field"><span>Motivo *</span><select name="motivo_codigo" required><option value="">Selecione</option><?php foreach($cancellationReasons as $code=>$label): ?><option value="<?= esc_attr($code) ?>"><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
                <label class="cif-field"><span>Justificativa administrativa *</span><textarea name="justificativa" minlength="10" maxlength="3000" rows="5" required></textarea><small>Explique por que esta emissão deve ser encerrada antes da ativação.</small></label>
                <div class="cif-form-actions"><button type="button" class="cif-button cif-button--ghost" data-modal-close>Voltar</button><button type="submit" class="cif-button cif-button--danger">Confirmar cancelamento</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>


<section class="cif-section cif-panel"><h2>QR Code e arte técnica</h2>
<?php if ($artifact === null): ?>
    <p>A arte técnica ainda não foi materializada. Ela será gerada automaticamente quando a carteira for visualizada ou quando a produção for iniciada.</p>
<?php else: ?>
    <div class="cif-detail-grid">
        <div><dl class="cif-definition-list"><div><dt>Artefato</dt><dd><?= esc_html((string)$artifact['tipo']) ?> v<?= esc_html((string)$artifact['versao']) ?></dd></div><div><dt>Template</dt><dd><?= esc_html((string)$artifact['template_version']) ?></dd></div><div><dt>Gerado em</dt><dd><?= esc_html($fmt($artifact['gerado_em'])) ?></dd></div><div><dt>URL base fixada</dt><dd><code><?= esc_html((string)($artifactSummary['qr_base_url'] ?? '—')) ?></code></dd></div></dl></div>
        <?php if ($qrUrl !== null): ?><div><div id="cif-qr-preview" class="cif-qr-preview" data-cif-qr-preview data-url="<?= esc_attr($qrUrl) ?>"><span class="cif-qr-preview__loading">Carregando QR…</span></div><p class="cif-muted cif-small">QR exclusivo desta emissão. O token não aparece no texto da tela.</p></div><?php endif; ?>
    </div>
<?php endif; ?>
<p class="cif-muted">A arte utiliza o padrão CR80 (85,60 × 53,98 mm). Este bloco é informativo; a produção física é controlada pela etapa acima.</p>
</section>

<section class="cif-section cif-panel"><h2>Token exclusivo do QR</h2><p>O token possui 256 bits aleatórios e não deriva de matrícula, CPF ou código da CIF. O valor fica criptografado no banco oficial.</p><?php if (is_string($qrToken)): ?><div class="cif-secret-box"><code><?= esc_html($qrToken) ?></code></div><?php else: ?><div class="cif-alert">Seu perfil pode consultar a emissão, mas não possui permissão para revelar o token QR integral.</div><?php endif; ?><p class="cif-muted">A arte de impressão utiliza o destino público fixado para esta emissão e não altera o token.</p></section>

<?php if ($prints !== []): ?>
<section class="cif-section cif-panel"><h2>Histórico de produção</h2>
    <div class="cif-table-wrap"><table class="cif-table"><thead><tr><th>Tentativa</th><th>Status</th><th>Equipamento</th><th>Solicitada</th><th>Concluída</th><th>Ação / resultado</th></tr></thead><tbody>
    <?php foreach ($prints as $print): ?><tr>
        <td>#<?= esc_html((string)$print['tentativa']) ?></td>
        <td><strong><?= esc_html((string)$print['status']) ?></strong></td>
        <td><?= esc_html((string)($print['impressora'] ?: '—')) ?></td>
        <td><?= esc_html($fmt($print['solicitada_em'])) ?><br><span class="cif-muted cif-small"><?= esc_html((string)$print['solicitada_por_nome']) ?></span></td>
        <td><?= esc_html($fmt($print['concluida_em'])) ?></td>
        <td>
            <?php if ((string)$print['status']==='ABERTA' && $canPrint): ?>
                <a class="cif-link-action" href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/impressao/'.(int)$print['id'])) ?>" target="_blank" rel="noopener">Continuar produção</a>
            <?php elseif ((string)$print['status']==='CONCLUIDA'): ?>
                Produção concluída
            <?php else: ?>
                <?= $print['erro'] ? esc_html((string)$print['erro']) : 'Falha registrada' ?>
            <?php endif; ?>
        </td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>

<section class="cif-section cif-panel"><h2>Snapshot da emissão</h2><div class="cif-biometry-summary"><div><span>Nome</span><strong><?= esc_html((string)$emission['nome_completo']) ?></strong></div><div><span>Matrícula</span><strong><?= esc_html((string)$emission['matricula_snapshot']) ?></strong></div><div><span>Situação funcional</span><strong><?= esc_html((string)$emission['situacao_funcional']) ?></strong></div><div><span>Signatário</span><strong><?= esc_html((string)$emission['signatario_nome']) ?></strong><small><?= esc_html((string)$emission['signatario_cargo']) ?></small></div></div><dl class="cif-definition-list"><div><dt>Naturalidade</dt><dd><?= esc_html((string)$emission['naturalidade']) ?></dd></div><div><dt>Data de nascimento</dt><dd><?= esc_html(wp_date('d/m/Y', strtotime((string)$emission['data_nascimento'].' UTC'))) ?></dd></div><div><dt>Tipo sanguíneo</dt><dd><?= esc_html((string)($emission['tipo_sanguineo'] ?: '—')) ?></dd></div><div><dt>Data de admissão</dt><dd><?= $emission['data_admissao'] ? esc_html(wp_date('d/m/Y',strtotime((string)$emission['data_admissao'].' UTC'))) : '—' ?></dd></div><div><dt>Hash do snapshot</dt><dd><code><?= esc_html(bin2hex((string)$emission['snapshot_hash'])) ?></code></dd></div></dl></section>
<section class="cif-section cif-panel" id="historico-cif">
    <div class="cif-section__head">
        <div><h2>Histórico e eventos da CIF</h2><p>Ocorrências, recolhimentos e mudanças de estado permanecem vinculados a esta emissão. Eles não criam um novo ciclo; um novo ciclo nasce somente quando um novo processo é aberto para o agente.</p></div>
        <?php if ((string)$emission['status_validade']==='ATIVA' && (string)$emission['status_fisico']==='ENTREGUE' && ($canRegisterOccurrence || $canRegisterRecollection)): ?>
        <div class="cif-inline-actions">
            <?php if($canRegisterOccurrence): ?><a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/ocorrencias/nova')) ?>">Registrar ocorrência</a><?php endif; ?>
            <?php if($canRegisterRecollection): ?><a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']).'/recolhimentos/novo')) ?>">Registrar recolhimento</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="cif-detail-grid">
        <div>
            <h3>Ocorrências desta emissão</h3>
            <div class="cif-table-wrap cif-table-wrap--embedded"><table class="cif-table"><thead><tr><th>Tipo</th><th>Status</th><th>Comunicação</th><th>Ação</th></tr></thead><tbody>
            <?php if (($emission['occurrences'] ?? []) === []): ?><tr><td colspan="4" class="cif-empty">Nenhuma ocorrência registrada nesta CIF.</td></tr><?php else: foreach ($emission['occurrences'] as $occurrence): ?><tr>
                <td><strong><?= esc_html((string)$occurrence['tipo_label']) ?></strong></td>
                <td><span class="cif-badge <?= (string)$occurrence['status']==='ABERTA' ? 'cif-badge--warning' : 'cif-badge--success' ?>"><?= esc_html((string)$occurrence['status_label']) ?></span></td>
                <td><?= esc_html($fmt($occurrence['data_comunicacao'])) ?><br><span class="cif-muted cif-small"><?= esc_html((string)$occurrence['registrado_por_nome']) ?></span></td>
                <td class="cif-table__actions"><a class="cif-link-action" href="<?= esc_url(home_url('/cif/ocorrencias/'.rawurlencode((string)$occurrence['uuid']))) ?>">Detalhes</a></td>
            </tr><?php endforeach; endif; ?></tbody></table></div>
        </div>
        <div>
            <h3>Recolhimentos / inutilizações</h3>
            <div class="cif-table-wrap cif-table-wrap--embedded"><table class="cif-table"><thead><tr><th>Motivo</th><th>Status</th><th>Registro</th><th>Ação</th></tr></thead><tbody>
            <?php if (($emission['recollections'] ?? []) === []): ?><tr><td colspan="4" class="cif-empty">Nenhum recolhimento vinculado a esta CIF.</td></tr><?php else: foreach ($emission['recollections'] as $recollection): ?><tr>
                <td><strong><?= esc_html((string)$recollection['motivo_label']) ?></strong></td>
                <td><span class="cif-badge"><?= esc_html((string)$recollection['status_label']) ?></span></td>
                <td><?= esc_html($fmt($recollection['recolhimento_solicitado_em'])) ?><br><span class="cif-muted cif-small"><?= esc_html((string)$recollection['registrado_por_nome']) ?></span></td>
                <td class="cif-table__actions"><a class="cif-link-action" href="<?= esc_url(home_url('/cif/recolhimentos/'.rawurlencode((string)$recollection['uuid']))) ?>">Detalhes</a></td>
            </tr><?php endforeach; endif; ?></tbody></table></div>
        </div>
    </div>

    <h3>Linha do tempo técnica</h3>
    <div class="cif-process-timeline"><?php foreach ($emission['events'] as $event): ?><article><div><strong><?= esc_html((string)$event['tipo_evento']) ?></strong><span><?= esc_html($fmt($event['ocorrido_em'])) ?></span></div><p><?= esc_html((string)($event['usuario_nome'] ?: 'Sistema')) ?><?php if ($event['status_anterior'] || $event['status_novo']): ?> · <?= esc_html((string)($event['status_anterior'] ?: '—')) ?> → <?= esc_html((string)($event['status_novo'] ?: '—')) ?><?php endif; ?></p></article><?php endforeach; ?></div>
</section>
<?php if ($qrUrl !== null): ?><script src="<?= esc_url($assetBaseUrl.'js/vendor/qr-bundle.js?v='.rawurlencode($pluginVersion)) ?>"></script><script src="<?= esc_url($assetBaseUrl.'js/qr-preview.js?v='.rawurlencode($pluginVersion)) ?>"></script><?php endif; ?>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
