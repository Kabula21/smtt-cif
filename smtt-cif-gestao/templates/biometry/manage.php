<?php
/** @var \SMTT\CIF\Gestao\Security\CifUser $currentUser */
/** @var array{agent:array<string,mixed>,collections:list<array<string,mixed>>,current:?array<string,mixed>} $overview */
/** @var array<string,string> $form */
/** @var string|null $error */
/** @var bool $canCapture */
/** @var bool $canInvalidate */
/** @var array{type:string,text:string}|null $notice */
$agent = $overview['agent'];
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-page-head">
    <div>
        <p class="cif-eyebrow">Biometria do Agente de Trânsito</p>
        <h1><?= esc_html((string) $agent['nome_completo']) ?></h1>
        <p>Matrícula <?= esc_html((string) $agent['matricula']) ?> · fotografia e assinatura versionadas.</p>
    </div>
    <a class="cif-button cif-button--secondary" href="<?= esc_url(home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']))) ?>">Voltar à ficha</a>
</section>

<?php if ($notice !== null): ?>
    <div class="cif-alert cif-alert--<?= esc_attr($notice['type']) ?>"><?= esc_html($notice['text']) ?></div>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<section class="cif-panel cif-section">
    <h2>Biometria válida mais recente</h2>
    <?php if ($overview['current'] === null): ?>
        <p>Não existe coleta biométrica válida para este agente.</p>
    <?php else: ?>
        <?php $current = $overview['current']; ?>
        <div class="cif-biometry-preview-grid">
            <figure class="cif-biometry-preview">
                <figcaption>Fotografia atual</figcaption>
                <?php if (!empty($current['foto_arquivo_uuid'])): ?>
                    <img src="<?= esc_url(home_url('/cif/biometria/arquivo/' . rawurlencode((string) $current['foto_arquivo_uuid']))) ?>" alt="Fotografia biométrica do agente">
                <?php endif; ?>
                <small><?= esc_html((string) $current['foto_largura']) ?>×<?= esc_html((string) $current['foto_altura']) ?> px<?php if ((int) $current['foto_dpi'] > 0): ?> · <?= esc_html((string) $current['foto_dpi']) ?> DPI (metadado)<?php endif; ?></small>
            </figure>
            <figure class="cif-biometry-preview cif-biometry-preview--signature">
                <figcaption>Assinatura atual</figcaption>
                <?php if (!empty($current['assinatura_arquivo_uuid'])): ?>
                    <img src="<?= esc_url(home_url('/cif/biometria/arquivo/' . rawurlencode((string) $current['assinatura_arquivo_uuid']))) ?>" alt="Assinatura biométrica do agente">
                <?php endif; ?>
                <small><?= esc_html((string) $current['assinatura_largura']) ?>×<?= esc_html((string) $current['assinatura_altura']) ?> px<?php if ((int) $current['assinatura_dpi'] > 0): ?> · <?= esc_html((string) $current['assinatura_dpi']) ?> DPI (metadado)<?php endif; ?></small>
            </figure>
        </div>
        <dl class="cif-definition-list">
            <div><dt>Coleta</dt><dd><code><?= esc_html((string) $current['uuid']) ?></code></dd></div>
            <div><dt>Registrada em</dt><dd><?= esc_html(mysql2date('d/m/Y H:i', (string) $current['coletada_em'] . ' UTC', true)) ?></dd></div>
            <div><dt>Operador</dt><dd><?= esc_html((string) $current['operador_nome']) ?></dd></div>
            <div><dt>Local</dt><dd><?= esc_html((string) ($current['local_coleta'] ?: 'Não informado')) ?></dd></div>
        </dl>
        <p class="cif-small cif-muted">A reutilização em futura emissão será verificada pela regra de emissão aplicável; este módulo não presume automaticamente prazo de reutilização.</p>
    <?php endif; ?>
</section>

<?php if ($canCapture): ?>
<section class="cif-panel cif-section">
    <h2>Nova coleta biométrica</h2>
    <p class="cif-muted">Uma nova coleta não apaga as anteriores. Fotografia e assinatura são armazenadas criptografadas fora da Biblioteca de Mídia do WordPress.</p>
    <form class="cif-form" method="post" enctype="multipart/form-data" action="<?= esc_url(home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']) . '/biometria')) ?>">
        <input type="hidden" name="smtt_cif_nonce" value="<?= esc_attr(smtt_cif_create_nonce('smtt_cif_biometry_capture_' . (string) $agent['uuid'])) ?>">
        <div class="cif-form-grid">
            <label class="cif-field">
                <span>Local da coleta</span>
                <input type="text" name="local_coleta" maxlength="150" value="<?= esc_attr((string) ($form['local_coleta'] ?? '')) ?>">
            </label>
            <label class="cif-field">
                <span>Observações</span>
                <input type="text" name="observacoes" maxlength="2000" value="<?= esc_attr((string) ($form['observacoes'] ?? '')) ?>">
            </label>
        </div>

        <div class="cif-biometry-form-grid">
            <fieldset class="cif-fieldset cif-biometry-capture-card">
                <legend>Fotografia</legend>
                <label class="cif-field">
                    <span>Arquivo JPEG ou PNG</span>
                    <input type="file" name="foto" accept="image/jpeg,image/png" required>
                    <small>Máximo 12 MiB. Não é necessário informar DPI. O sistema valida a resolução real em pixels e otimiza automaticamente imagens muito grandes para uso na CIF.</small>
                </label>
                <label class="cif-checkbox"><input type="checkbox" name="foto_fundo_branco" value="1" <?= !empty($form['foto_fundo_branco']) ? 'checked' : '' ?> required><span>Fundo branco confirmado.</span></label>
                <label class="cif-checkbox"><input type="checkbox" name="foto_padrao_icao" value="1" <?= !empty($form['foto_padrao_icao']) ? 'checked' : '' ?> required><span>Enquadramento/apresentação no padrão ICAO confirmado.</span></label>
                <label class="cif-checkbox"><input type="checkbox" name="foto_apresentacao_pessoal" value="1" <?= !empty($form['foto_apresentacao_pessoal']) ? 'checked' : '' ?> required><span>Apresentação pessoal conforme o Regulamento de Uniformes.</span></label>
                <label class="cif-checkbox"><input type="checkbox" name="foto_camisa_caqui" value="1" <?= !empty($form['foto_camisa_caqui']) ? 'checked' : '' ?> required><span>Camisa caqui do uniforme operacional confirmada.</span></label>
            </fieldset>

            <fieldset class="cif-fieldset cif-biometry-capture-card">
                <legend>Assinatura</legend>
                <label class="cif-field">
                    <span>Arquivo JPEG ou PNG</span>
                    <input type="file" name="assinatura" accept="image/jpeg,image/png" required>
                    <small>Máximo 12 MiB. PNG com fundo transparente é recomendado para a carteira. Não é necessário informar DPI; imagens muito grandes são otimizadas sem alterar a proporção.</small>
                </label>
                <label class="cif-checkbox"><input type="checkbox" name="assinatura_coleta_eletronica" value="1" <?= !empty($form['assinatura_coleta_eletronica']) ? 'checked' : '' ?> required><span>Confirmo que a imagem corresponde à assinatura coletada eletronicamente na unidade responsável.</span></label>
            </fieldset>
        </div>
        <div class="cif-form-actions">
            <button class="cif-button cif-button--primary" type="submit">Registrar nova coleta</button>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="cif-section">
    <div class="cif-section__head">
        <div><h2>Histórico de coletas</h2><p>Coletas anteriores são preservadas mesmo quando invalidadas ou substituídas por nova coleta.</p></div>
    </div>
    <div class="cif-table-wrap">
        <table class="cif-table cif-table--biometry">
            <thead><tr><th>Coleta</th><th>Fotografia</th><th>Assinatura</th><th>Situação</th><th>Operador/local</th><th></th></tr></thead>
            <tbody>
            <?php if ($overview['collections'] === []): ?>
                <tr><td class="cif-empty" colspan="6">Nenhuma coleta biométrica registrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($overview['collections'] as $collection): ?>
                <tr>
                    <td><?= esc_html(mysql2date('d/m/Y H:i', (string) $collection['coletada_em'] . ' UTC', true)) ?><br><code class="cif-small"><?= esc_html(substr((string) $collection['uuid'], 0, 8)) ?>…</code></td>
                    <td>
                        <?php if (!empty($collection['foto_arquivo_uuid'])): ?>
                            <a href="<?= esc_url(home_url('/cif/biometria/arquivo/' . rawurlencode((string) $collection['foto_arquivo_uuid']))) ?>" target="_blank" rel="noopener">Visualizar</a><br>
                        <?php endif; ?>
                        <span class="cif-small cif-muted"><?= esc_html((string) $collection['foto_largura']) ?>×<?= esc_html((string) $collection['foto_altura']) ?> px<?php if ((int) $collection['foto_dpi'] > 0): ?> · <?= esc_html((string) $collection['foto_dpi']) ?> DPI<?php endif; ?></span>
                    </td>
                    <td>
                        <?php if (!empty($collection['assinatura_arquivo_uuid'])): ?>
                            <a href="<?= esc_url(home_url('/cif/biometria/arquivo/' . rawurlencode((string) $collection['assinatura_arquivo_uuid']))) ?>" target="_blank" rel="noopener">Visualizar</a><br>
                        <?php endif; ?>
                        <span class="cif-small cif-muted"><?= esc_html((string) $collection['assinatura_largura']) ?>×<?= esc_html((string) $collection['assinatura_altura']) ?> px<?php if ((int) $collection['assinatura_dpi'] > 0): ?> · <?= esc_html((string) $collection['assinatura_dpi']) ?> DPI<?php endif; ?></span>
                    </td>
                    <td>
                        <?php if ((string) $collection['status'] === 'VALIDA'): ?>
                            <span class="cif-badge cif-badge--success">Válida</span>
                        <?php else: ?>
                            <span class="cif-badge cif-badge--warning">Invalidada</span>
                            <?php if (!empty($collection['motivo_invalidacao'])): ?><div class="cif-small cif-muted cif-biometry-reason"><?= esc_html((string) $collection['motivo_invalidacao']) ?></div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= esc_html((string) $collection['operador_nome']) ?><br><span class="cif-small cif-muted"><?= esc_html((string) ($collection['local_coleta'] ?: 'Local não informado')) ?></span></td>
                    <td class="cif-table__actions">
                        <?php if ($canInvalidate && (string) $collection['status'] === 'VALIDA'): ?>
                            <form method="post" action="<?= esc_url(home_url('/cif/agentes/' . rawurlencode((string) $agent['uuid']) . '/biometria/' . rawurlencode((string) $collection['uuid']) . '/invalidar')) ?>" class="cif-invalidate-form">
                                <input type="hidden" name="smtt_cif_nonce" value="<?= esc_attr(smtt_cif_create_nonce('smtt_cif_biometry_invalidate_' . (string) $collection['uuid'])) ?>">
                                <input type="text" name="motivo" minlength="10" maxlength="500" required placeholder="Justificativa da invalidação">
                                <button class="cif-button cif-button--danger" type="submit">Invalidar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
