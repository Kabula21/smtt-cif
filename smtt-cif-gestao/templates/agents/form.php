<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
/** @var string $mode */
/** @var array<string,string> $form */
/** @var array<string,mixed>|null $agent */
/** @var string|null $error */
/** @var array<string,string> $statusOptions */
/** @var list<string> $bloodTypes */
/** @var bool $canDelete */
/** @var array<string,int> $dependencies */
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
$isEdit = $mode === 'edit';
$action = $isEdit && $agent !== null
    ? home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']) . '/editar')
    : home_url('/cif/agentes/novo');
$nonceAction = $isEdit && $agent !== null
    ? 'smtt_cif_agent_edit_' . (string) $agent['uuid']
    : 'smtt_cif_agent_create';
?>
<section class="cif-page-head">
    <div>
        <p class="cif-eyebrow">Agentes de Trânsito</p>
        <h1><?= $isEdit ? 'Editar cadastro' : 'Cadastrar agente' ?></h1>
        <p><?= $isEdit ? 'Alterações biográficas criam uma nova versão; o registro anterior permanece preservado.' : 'Cadastre os dados biográficos previstos para a emissão da CIF.' ?></p>
    </div>
</section>

<?php if ($error !== null): ?>
    <div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<form class="cif-panel cif-form" method="post" action="<?= esc_url($action) ?>" autocomplete="off">
    <?php smtt_cif_nonce_field($nonceAction, 'smtt_cif_nonce'); ?>
    <?php if ($isEdit && $agent !== null): ?>
        <input type="hidden" name="lock_version" value="<?= esc_attr((string) $agent['lock_version']) ?>">
    <?php endif; ?>

    <div class="cif-form-grid">
        <label class="cif-field">
            <span>Matrícula *</span>
            <input type="text" name="matricula" maxlength="30" required value="<?= esc_attr($form['matricula']) ?>">
        </label>
        <label class="cif-field cif-field--wide">
            <span>Nome completo *</span>
            <input type="text" name="nome_completo" maxlength="200" required value="<?= esc_attr($form['nome_completo']) ?>">
        </label>
        <label class="cif-field">
            <span>CPF *</span>
            <input type="text" name="cpf" inputmode="numeric" maxlength="14" required value="<?= esc_attr($form['cpf']) ?>" placeholder="000.000.000-00">
            <small>Armazenado criptografado; não é usado para consulta pública.</small>
        </label>
        <label class="cif-field">
            <span>Data de nascimento *</span>
            <input type="date" name="data_nascimento" required value="<?= esc_attr($form['data_nascimento']) ?>">
        </label>
        <label class="cif-field">
            <span>Naturalidade *</span>
            <input type="text" name="naturalidade" maxlength="150" required value="<?= esc_attr($form['naturalidade']) ?>">
        </label>
        <label class="cif-field">
            <span>Tipo sanguíneo *</span>
            <select name="tipo_sanguineo" required>
                <option value="">Selecione</option>
                <?php foreach ($bloodTypes as $type): ?>
                    <option value="<?= esc_attr($type) ?>" <?= selected($form['tipo_sanguineo'], $type, false) ?>><?= esc_html($type) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="cif-field">
            <span>Data de admissão *</span>
            <input type="date" name="data_admissao" required value="<?= esc_attr($form['data_admissao']) ?>">
        </label>
        <label class="cif-field">
            <span>Situação funcional *</span>
            <select name="situacao_funcional" required>
                <?php foreach ($statusOptions as $code => $label): ?>
                    <option value="<?= esc_attr($code) ?>" <?= selected($form['situacao_funcional'], $code, false) ?>><?= esc_html($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="cif-field cif-field--wide">
            <span>Referência da fonte</span>
            <input type="text" name="fonte_referencia" maxlength="150" value="<?= esc_attr($form['fonte_referencia']) ?>" placeholder="Ex.: ficha funcional, processo ou sistema de pessoal">
            <small>A origem desta alteração fica registrada na versão biográfica.</small>
        </label>
    </div>

    <div class="cif-form-actions cif-form-actions--split">
        <a class="cif-button cif-button--secondary" href="<?= esc_url($isEdit && $agent !== null ? home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid'])) : home_url('/cif/agentes')) ?>">Cancelar</a>
        <button class="cif-button cif-button--primary" type="submit"><?= $isEdit ? 'Salvar nova versão' : 'Cadastrar agente' ?></button>
    </div>
</form>

<?php if ($isEdit && $agent !== null):
    $hasIssuedEmission = (int) ($dependencies['emissoes'] ?? 0) > 0;
    $removable = !$hasIssuedEmission;
?>
<section class="cif-panel cif-danger-zone" aria-labelledby="cif-agent-management-title">
    <div class="cif-section__head">
        <div>
            <p class="cif-eyebrow">Gestão do cadastro</p>
            <h2 id="cif-agent-management-title">Editar, inativar ou excluir</h2>
            <p>Enquanto nenhuma CIF tiver sido emitida, um cadastro lançado por engano pode ser descartado junto com biometria, validações e processos preparatórios. Depois da primeira emissão, o histórico passa a ser permanente.</p>
        </div>
    </div>
    <div class="cif-inline-actions">
        <form method="post" action="<?= esc_url(home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']) . '/status')) ?>">
            <?php smtt_cif_nonce_field('smtt_cif_agent_status_' . (string) $agent['uuid'], 'smtt_cif_nonce'); ?>
            <input type="hidden" name="active" value="<?= (int) $agent['ativo'] === 1 ? '0' : '1' ?>">
            <button class="cif-button <?= (int) $agent['ativo'] === 1 ? 'cif-button--secondary' : 'cif-button--primary' ?>" type="submit">
                <?= (int) $agent['ativo'] === 1 ? 'Inativar cadastro' : 'Reativar cadastro' ?>
            </button>
        </form>

        <?php if ($canDelete && $removable): ?>
            <form method="post" action="<?= esc_url(home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']) . '/excluir')) ?>" data-confirm="O cadastro e todos os vínculos preparatórios serão removidos. Tem certeza?" data-confirm-title="Descartar cadastro antes da emissão?" data-confirm-label="Sim, descartar cadastro">
                <?php smtt_cif_nonce_field('smtt_cif_agent_delete_' . (string) $agent['uuid'], 'smtt_cif_nonce'); ?>
                <label class="cif-field" style="min-width:320px"><span>Justificativa da exclusão</span><textarea name="delete_reason" rows="2" maxlength="500" required placeholder="Ex.: cadastro lançado com CPF incorreto; será refeito."></textarea></label>
                <button class="cif-button cif-button--danger" type="submit">Descartar cadastro e preparação</button>
            </form>
        <?php elseif ($canDelete): ?>
            <button class="cif-button cif-button--danger" type="button" disabled aria-disabled="true">Exclusão bloqueada após emissão</button>
            <span class="cif-muted cif-small">Já existe CIF emitida para este agente. Corrija o cadastro e, quando aplicável, invalide/substitua a carteira; a emissão anterior permanece no histórico.</span>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
