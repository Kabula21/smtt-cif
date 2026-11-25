<?php
/** @var string $mode */
/** @var array<string,mixed>|null $agent */
/** @var list<array<string,mixed>> $candidates */
/** @var array<string,mixed>|null $process */
/** @var array<string,string> $form */
/** @var array<string,string> $typeOptions */
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-page-head"><div><p class="cif-eyebrow">Processos de emissão</p><h1><?= $mode === 'create' ? 'Novo processo' : 'Editar processo' ?></h1><p>O protocolo CIF é gerado automaticamente ao criar o processo. O código da carteira e o token QR só são gerados na emissão.</p></div></section>
<?php if ($error): ?><div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div><?php endif; ?>

<?php if ($mode === 'create' && $agent === null): ?>
<section class="cif-panel"><h2>1. Selecione o Agente de Trânsito</h2><form method="get" action="<?= esc_url(home_url('/cif/processos/novo')) ?>" class="cif-search"><label class="cif-field cif-field--grow"><span>Nome ou matrícula</span><input type="text" name="q" value="<?= esc_attr($searchQuery) ?>"></label><button class="cif-button cif-button--primary" type="submit">Pesquisar</button></form></section>
<section class="cif-section"><div class="cif-table-wrap"><table class="cif-table"><thead><tr><th>Agente</th><th>Situação</th><th></th></tr></thead><tbody><?php if ($candidates === []): ?><tr><td colspan="3" class="cif-empty">Nenhum agente encontrado.</td></tr><?php else: foreach ($candidates as $candidate): ?><tr><td><strong><?= esc_html((string) $candidate['nome_completo']) ?></strong><br><span class="cif-muted cif-small">Matrícula <?= esc_html((string) $candidate['matricula']) ?></span></td><td><?= esc_html((string) $candidate['situacao_funcional_atual']) ?></td><td class="cif-table__actions"><a class="cif-button cif-button--secondary" href="<?= esc_url(add_query_arg('agente', (string) $candidate['uuid'], home_url('/cif/processos/novo'))) ?>">Selecionar</a></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
<?php else: ?>
<section class="cif-panel">
    <h2><?= $mode === 'create' ? '2. Dados do processo' : 'Dados do processo' ?></h2>
    <p><strong><?= esc_html((string) $agent['nome_completo']) ?></strong> · Matrícula <?= esc_html((string) $agent['matricula']) ?></p>
    <?php if ($mode === 'create'): ?><p><a class="cif-link-action" href="<?= esc_url(home_url('/cif/processos/novo')) ?>">Trocar agente</a></p><?php endif; ?>
    <form method="post" class="cif-form cif-form-grid">
        <?php smtt_cif_nonce_field($mode === 'create' ? 'smtt_cif_process_create' : 'smtt_cif_process_edit_' . (string) $process['uuid'], 'smtt_cif_nonce'); ?>
        <input type="hidden" name="agente_uuid" value="<?= esc_attr((string) $agent['uuid']) ?>">
        <?php if ($mode === 'create' && !empty($form['occurrence_uuid'])): ?><input type="hidden" name="occurrence_uuid" value="<?= esc_attr((string) $form['occurrence_uuid']) ?>"><div class="cif-alert cif-alert--info cif-field--wide">Este processo será vinculado à ocorrência registrada. O fato, a comunicação e os documentos da ocorrência serão reaproveitados de forma auditada.</div><?php endif; ?>
        <?php if ($mode === 'edit'): ?><input type="hidden" name="lock_version" value="<?= esc_attr((string) $process['lock_version']) ?>"><?php endif; ?>
        <label class="cif-field"><span>Tipo do processo</span>
            <?php if ($mode === 'edit' && (string) $process['status_codigo'] !== 'RASCUNHO'): ?>
                <input type="hidden" name="tipo_codigo" value="<?= esc_attr((string) $form['tipo_codigo']) ?>"><input type="text" value="<?= esc_attr($typeOptions[$form['tipo_codigo']] ?? $form['tipo_codigo']) ?>" disabled>
            <?php else: ?>
                <select name="tipo_codigo" required><?php foreach ($typeOptions as $code => $label): ?><option value="<?= esc_attr($code) ?>" <?= selected($form['tipo_codigo'], $code, false) ?>><?= esc_html($label) ?></option><?php endforeach; ?></select>
            <?php endif; ?>
        </label>
        <?php if ($mode === 'edit'): ?>
        <label class="cif-field"><span>Protocolo CIF</span><input type="text" value="<?= esc_attr((string) $process['numero_protocolo']) ?>" readonly><small>Gerado automaticamente pelo Sistema CIF e imutável.</small></label><label class="cif-field"><span>Ciclo do agente</span><input type="text" value="<?= esc_attr(str_pad((string)($process['ciclo_numero'] ?? 0),2,'0',STR_PAD_LEFT)) ?>" readonly><small>Reservado quando este processo foi criado.</small></label>
        <?php else: ?>
        <div class="cif-field"><span>Protocolo e ciclo</span><div class="cif-auto-value">Gerados automaticamente ao salvar</div><small>O novo processo recebe o próximo ciclo individual deste agente (01, 02, 03…) e um protocolo CIF próprio.</small></div>
        <?php endif; ?>
        <label class="cif-field"><span>Protocolo externo / referência administrativa</span><input type="text" name="protocolo_externo" maxlength="150" value="<?= esc_attr($form['protocolo_externo']) ?>" placeholder="Opcional: SEI, protocolo geral, memorando etc."><small>Use somente quando existir uma referência emitida por outro sistema ou processo administrativo.</small></label>
        <label class="cif-field"><span>Data/hora do fato</span><input type="datetime-local" name="fato_em" value="<?= esc_attr($form['fato_em']) ?>"><small>Obrigatória para perda, furto e roubo.</small></label>
        <label class="cif-field"><span>Data/hora da comunicação à SMTT</span><input type="datetime-local" name="comunicado_em" value="<?= esc_attr($form['comunicado_em']) ?>"><small>Obrigatória para perda, furto e roubo. Não há carência automática de dias para nova emissão.</small></label>
        <label class="cif-field cif-field--wide"><span>Justificativa / informações do processo</span><textarea name="justificativa" rows="5" maxlength="4000"><?= esc_textarea($form['justificativa']) ?></textarea><small>Obrigatória, com pelo menos 10 caracteres, exceto para primeira emissão.</small></label>
        <div class="cif-form-actions cif-form-actions--split"><a class="cif-button cif-button--ghost" href="<?= esc_url($mode === 'create' ? home_url('/cif/processos') : home_url('/cif/processos/' . rawurlencode((string) $process['uuid']))) ?>">Cancelar</a><button class="cif-button cif-button--primary" type="submit"><?= $mode === 'create' ? 'Criar processo' : 'Salvar alterações' ?></button></div>
    </form>
</section>
<?php endif; ?>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
