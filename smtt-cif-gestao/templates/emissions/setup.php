<?php
/** @var array<string,mixed> $overview */
$bodyClass = 'cif-page--workspace';
$numbering = $overview['numbering'];
$currentSigner = $overview['current_signer'];
$fmt = static fn($value): string => is_string($value) && $value !== '' ? wp_date('d/m/Y H:i', strtotime($value . ' UTC')) : '—';
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-page-head cif-page-head--compact">
    <div><p class="cif-eyebrow">Configurações</p><h1>Preparação para emissão</h1><p>O ciclo inicial é criado automaticamente; aqui ficam apenas as configurações institucionais e operações extraordinárias.</p></div>
    <a class="cif-button cif-button--ghost" href="<?= esc_url(home_url('/cif/emissoes')) ?>">Voltar às emissões</a>
</section>
<?php if ($notice): ?><div class="cif-alert cif-alert--<?= esc_attr($notice['type']) ?>"><?= esc_html($notice['text']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div><?php endif; ?>

<section class="cif-workspace-card cif-settings-console">
    <div class="cif-console-strip">
        <span><small>Regra do ciclo</small><strong>Por agente/processo</strong></span>
        <span><small>Último sequencial</small><strong><?= str_pad((string)($numbering['ultimo_sequencial'] ?? 0),4,'0',STR_PAD_LEFT) ?></strong></span>
        <span><small>Signatário</small><strong><?= is_array($currentSigner) ? esc_html((string)$currentSigner['nome']) : 'Não configurado' ?></strong></span>
        <span><small>Portal de validação</small><strong><?= is_string($validationBaseUrl) && $validationBaseUrl !== '' ? 'Configurado' : 'Pendente' ?></strong></span>
    </div>

    <details class="cif-console-section" open>
        <summary><span><strong>Numeração da CIF</strong><small>O ciclo é individual por agente; o sequencial final é global e nunca reinicia.</small></span></summary>
        <div class="cif-console-section__body">
            <div class="cif-settings-grid">
                <dl class="cif-definition-list cif-definition-list--compact">
                    <div><dt>Regra do código</dt><dd><code>AJU##SEBR@@@@</code></dd></div>
                    <div><dt>Ciclo ##</dt><dd>01, 02, 03… por agente, reservado automaticamente ao abrir cada novo processo.</dd></div>
                    <div><dt>Último sequencial global @@@@</dt><dd><?= str_pad((string)($numbering['ultimo_sequencial'] ?? 0),4,'0',STR_PAD_LEFT) ?></dd></div>
                    <div><dt>Próximo sequencial global</dt><dd><?= (int)($numbering['ultimo_sequencial'] ?? 0) < 9999 ? str_pad((string)((int)($numbering['ultimo_sequencial'] ?? 0)+1),4,'0',STR_PAD_LEFT) : 'Limite atingido' ?></dd></div>
                </dl>
                <div class="cif-alert cif-alert--info"><strong>Exemplo:</strong> se um agente está no ciclo 02 e o próximo sequencial global é 0048, a nova CIF será <code>AJU02SEBR0048</code>. Ocorrências, recuperações e recolhimentos não mudam o ciclo; somente a abertura de um novo processo do agente.</div>
            </div>
        </div>
    </details>

    <details class="cif-console-section" <?= !is_array($currentSigner) ? 'open' : '' ?>>
        <summary><span><strong>Signatário institucional</strong><small>Assinatura vigente utilizada nas novas emissões.</small></span><span class="cif-badge <?= is_array($currentSigner) ? 'cif-badge--success' : 'cif-badge--warning' ?>"><?= is_array($currentSigner) ? 'Configurado' : 'Pendente' ?></span></summary>
        <div class="cif-console-section__body">
            <div class="cif-settings-grid">
                <div><?php if (is_array($currentSigner)): ?><dl class="cif-definition-list cif-definition-list--compact"><div><dt>Nome</dt><dd><?= esc_html((string)$currentSigner['nome']) ?></dd></div><div><dt>Cargo</dt><dd><?= esc_html((string)$currentSigner['cargo']) ?></dd></div><div><dt>Vigência</dt><dd>desde <?= esc_html($fmt($currentSigner['vigencia_inicio'])) ?></dd></div><div><dt>Ato</dt><dd><?= esc_html((string)($currentSigner['ato_referencia'] ?: '—')) ?></dd></div></dl><?php else: ?><div class="cif-alert cif-alert--warning">Nenhum signatário vigente. A geração de CIF está bloqueada até o cadastro.</div><?php endif; ?></div>
                <?php if ($canManageSigner): ?><form method="post" enctype="multipart/form-data" action="<?= esc_url(home_url('/cif/emissoes/preparacao/signatario')) ?>" class="cif-form cif-form--compact" data-confirm="Cadastrar esta nova versão do signatário institucional?">
                    <?php smtt_cif_nonce_field('smtt_cif_create_signer','smtt_cif_nonce'); ?>
                    <div class="cif-form-grid"><label class="cif-field"><span>Nome *</span><input type="text" name="nome" maxlength="200" required></label><label class="cif-field"><span>Cargo *</span><input type="text" name="cargo" maxlength="150" value="Superintendente" required></label></div>
                    <label class="cif-field"><span>Ato/referência</span><input type="text" name="ato_referencia" maxlength="200"></label>
                    <label class="cif-field"><span>Imagem da assinatura *</span><input type="file" name="assinatura" accept="image/jpeg,image/png" required><small>JPEG ou PNG, até 5 MB. PNG com fundo transparente é recomendado. Imagens muito grandes são otimizadas automaticamente e armazenadas de forma privada e criptografada.</small></label>
                    <div class="cif-form-actions"><button class="cif-button cif-button--primary" type="submit">Cadastrar signatário</button></div>
                </form><?php endif; ?>
            </div>
        </div>
    </details>

    <details class="cif-console-section" <?= !is_string($validationBaseUrl) || $validationBaseUrl === '' ? 'open' : '' ?>>
        <summary><span><strong>Portal público de validação</strong><small>Endereço utilizado para formar o QR Code de cada emissão.</small></span><span class="cif-badge <?= is_string($validationBaseUrl) && $validationBaseUrl !== '' ? 'cif-badge--success' : 'cif-badge--warning' ?>"><?= is_string($validationBaseUrl) && $validationBaseUrl !== '' ? 'Configurado' : 'Pendente' ?></span></summary>
        <div class="cif-console-section__body">
            <?php if (is_string($validationBaseUrl) && $validationBaseUrl !== ''): ?><div class="cif-alert cif-alert--success"><strong>URL atual:</strong> <code><?= esc_html($validationBaseUrl) ?></code></div><?php endif; ?>
            <?php if ($canEditConfig): ?><form method="post" action="<?= esc_url(home_url('/cif/emissoes/preparacao/url-validacao')) ?>" class="cif-form cif-form--compact"><?php smtt_cif_nonce_field('smtt_cif_validation_url','smtt_cif_nonce'); ?><label class="cif-field"><span>URL base oficial de validação *</span><input type="url" name="validation_base_url" maxlength="450" required value="<?= esc_attr((string)($validationBaseUrl ?? '')) ?>" placeholder="https://portal.smtt.aracaju.se.gov.br/validar"><small>Informe até <strong>/validar</strong>, sem token, query string ou fragmento. Produção exige HTTPS.</small></label><div class="cif-form-actions"><button class="cif-button cif-button--primary" type="submit">Salvar URL</button></div></form><?php endif; ?>
        </div>
    </details>

    <details class="cif-console-section">
        <summary><span><strong>Histórico institucional</strong><small>Versões de signatários.</small></span></summary>
        <div class="cif-console-section__body cif-settings-history">
            <h3>Signatários</h3><div class="cif-table-wrap cif-table-wrap--embedded"><table class="cif-table"><thead><tr><th>Nome</th><th>Cargo</th><th>Vigência</th><th>Ato</th><th>Cadastrado por</th></tr></thead><tbody><?php foreach ($overview['signers'] as $signer): ?><tr><td><strong><?= esc_html((string)$signer['nome']) ?></strong></td><td><?= esc_html((string)$signer['cargo']) ?></td><td><?= esc_html($fmt($signer['vigencia_inicio'])) ?> → <?= $signer['vigencia_fim'] ? esc_html($fmt($signer['vigencia_fim'])) : 'atual' ?></td><td><?= esc_html((string)($signer['ato_referencia'] ?: '—')) ?></td><td><?= esc_html((string)$signer['criado_por_nome']) ?></td></tr><?php endforeach; if ($overview['signers']===[]): ?><tr><td colspan="5" class="cif-empty">Nenhum signatário cadastrado.</td></tr><?php endif; ?></tbody></table></div>
        </div>
    </details>
</section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
