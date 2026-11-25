<?php
/** @var array<string,mixed> $emission */
/** @var array<string,string> $typeOptions */
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
$nowLocal = wp_date('Y-m-d\TH:i');
?>
<section class="cif-page-head"><div><p class="cif-eyebrow">Ocorrência pós-emissão</p><h1>Registrar ocorrência</h1><p><strong><?= esc_html((string)$emission['nome_completo']) ?></strong> · Matrícula <?= esc_html((string)$emission['matricula_snapshot']) ?> · <code><?= esc_html((string)$emission['codigo_validacao']) ?></code></p></div></section>
<?php if($error): ?><div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div><?php endif; ?>
<section class="cif-panel"><div class="cif-alert cif-alert--warning"><strong>Perda, furto ou roubo:</strong> o registro suspende imediatamente a validade da CIF e altera sua situação física para <strong>Não localizada</strong>. O portal público receberá atualização pela outbox.</div>
<form method="post" enctype="multipart/form-data" class="cif-form cif-form-grid">
<?php smtt_cif_nonce_field('smtt_cif_occurrence_create_'.(string)$emission['uuid'],'smtt_cif_nonce'); ?>
<label class="cif-field"><span>Tipo da ocorrência</span><select name="tipo_codigo" required><option value="">Selecione</option><?php foreach($typeOptions as $code=>$label): ?><option value="<?= esc_attr($code) ?>"><?= esc_html($label) ?></option><?php endforeach; ?></select></label>
<label class="cif-field"><span>Data/hora do fato</span><input type="datetime-local" name="data_fato" max="<?= esc_attr($nowLocal) ?>" required></label>
<label class="cif-field"><span>Data/hora da comunicação à SMTT</span><input type="datetime-local" name="data_comunicacao" value="<?= esc_attr($nowLocal) ?>" required></label>
<label class="cif-field"><span>Número do BO</span><input type="text" name="bo_numero" maxlength="100"><small>Obrigatório para perda, furto e roubo.</small></label>
<label class="cif-field"><span>Data do BO</span><input type="date" name="bo_data"></label>
<label class="cif-field"><span>Órgão do BO</span><input type="text" name="bo_orgao" maxlength="150"></label>
<label class="cif-field cif-field--wide"><span>BO / documento comprobatório</span><input type="file" name="documento" accept="application/pdf,image/jpeg,image/png"><small>PDF, JPEG ou PNG, até 10 MB. Obrigatório nos casos de extravio.</small></label>
<label class="cif-field cif-field--wide"><span>Detalhes complementares</span><textarea name="detalhes" rows="5" maxlength="3000"></textarea></label>
<div class="cif-form-actions cif-form-actions--split"><a class="cif-button cif-button--ghost" href="<?= esc_url(home_url('/cif/emissoes/'.rawurlencode((string)$emission['uuid']))) ?>">Cancelar</a><button class="cif-button cif-button--primary" type="submit">Registrar ocorrência</button></div>
</form></section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
