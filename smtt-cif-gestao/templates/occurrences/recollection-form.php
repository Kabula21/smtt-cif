<?php
/** @var array<string,mixed> $emission */
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-page-head"><div><p class="cif-eyebrow">Recolhimento administrativo</p><h1>Registrar recolhimento</h1><p><strong><?= esc_html((string)$emission['nome_completo']) ?></strong> · <code><?= esc_html((string)$emission['codigo_validacao']) ?></code></p></div></section>
<?php if($error): ?><div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div><?php endif; ?>
<section class="cif-panel"><form method="post" class="cif-form cif-form-grid"><?php smtt_cif_nonce_field('smtt_cif_recollection_create_'.(string)$emission['uuid'],'smtt_cif_nonce'); ?>
<label class="cif-field"><span>Motivo</span><select name="motivo_codigo" required><option value="">Selecione</option><?php foreach($reasonOptions as $code=>$label): ?><option value="<?= esc_attr($code) ?>"><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
<label class="cif-field"><span>Data/hora do fato/ato</span><input type="datetime-local" name="fato_em" required></label>
<label class="cif-field"><span>Referência do ato/processo</span><input type="text" name="referencia" maxlength="200"></label>
<label class="cif-field cif-field--wide"><span>Observações</span><textarea name="observacoes" rows="4" maxlength="2000"></textarea></label>
<label class="cif-checkbox cif-field--wide"><input type="checkbox" name="recolhida_agora" value="1"><span>Confirmo que a CIF foi fisicamente recolhida nesta operação. Para exoneração, vacância e substituição por novo modelo, esta confirmação é obrigatória. Em falecimento, pode permanecer pendente por até 60 dias.</span></label>
<div class="cif-alert cif-alert--warning cif-field--wide">Exoneração e vacância terão inutilização bloqueada por 2 anos a partir do fato. Substituição por novo modelo pode ser inutilizada após o recolhimento. Falecimento permite diligência de até 60 dias para devolução.</div>
<div class="cif-form-actions cif-form-actions--split"><a class="cif-button cif-button--ghost" href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']))) ?>">Cancelar</a><button class="cif-button cif-button--primary" type="submit">Registrar</button></div>
</form></section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
