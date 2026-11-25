<?php
/** @var array<string,mixed> $config */
/** @var array<string,mixed> $state */
/** @var array<string,mixed> $stats */
/** @var list<array<string,mixed>> $events */
/** @var array<string,mixed> $scheduler */
$automaticScheduled = !empty($scheduler['queue_scheduled']) && !empty($scheduler['reconcile_scheduled']);
$automaticReady = !empty($config['enabled']) && $automaticScheduled && !empty($config['portal_base_url']) && !empty($config['active_key']) && !empty($config['key_consistent']);
$automaticError = (string)($state['ultimo_erro_automatico'] ?? '');
$automaticLabel = empty($config['enabled']) ? 'Automação desabilitada' : ($automaticScheduled ? ($automaticError !== '' ? 'Automação ativa com atenção' : 'Automação ativa') : 'Agendamento pendente');
$nextQueue = !empty($scheduler['next_queue']) ? wp_date('d/m/Y H:i:s', (int)$scheduler['next_queue']) : '-';
$nextReconcile = !empty($scheduler['next_reconcile']) ? wp_date('d/m/Y H:i:s', (int)$scheduler['next_reconcile']) : '-';
$bodyClass = 'cif-page--workspace';
require SMTT_CIF_GESTAO_PATH . 'templates/layout/header.php';
?>
<section class="cif-page-head cif-page-head--compact">
    <div><p class="cif-eyebrow">Integração interno → público</p><h1>Sincronização</h1><p>Depois de configurada e habilitada, a atualização do portal público ocorre automaticamente em segundo plano.</p></div>
</section>
<?php if ($notice): ?><div class="cif-alert cif-alert--<?= esc_attr($notice['type']) ?>"><?= esc_html($notice['text']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="cif-alert cif-alert--error"><?= esc_html($error) ?></div><?php endif; ?>
<?php if (isset($config['key_consistent']) && !$config['key_consistent']): ?><div class="cif-alert cif-alert--error">O banco e o keyring privado não concordam sobre a chave Ed25519 ativa. Não execute a sincronização até corrigir a inconsistência.</div><?php endif; ?>

<section class="cif-workspace-card cif-sync-console">
    <div class="cif-console-strip">
        <span><strong><?= (int)$stats['pending'] ?></strong> pendentes</span>
        <span><strong><?= (int)$stats['retrying'] ?></strong> em retry</span>
        <span class="<?= (int)$stats['attention'] > 0 ? 'is-danger' : '' ?>"><strong><?= (int)$stats['attention'] ?></strong> atenção</span>
        <span><strong><?= (int)$stats['delivered'] ?></strong> entregues</span>
        <span class="cif-console-strip__status"><span class="cif-status-dot <?= !$automaticReady ? 'is-off' : '' ?>"></span><?= esc_html($automaticLabel) ?></span>
    </div>

    <section class="cif-sync-primary">
        <div class="cif-sync-primary__copy">
            <p class="cif-eyebrow">Operação automática</p>
            <h2><?= $automaticReady ? 'Sincronização automática ativa' : 'Sincronização automática ainda não está pronta' ?></h2>
            <p><?= $automaticReady
                ? 'Nenhuma ação diária é necessária. Alterações públicas entram na fila local e são enviadas em segundo plano, sem bloquear o trabalho dos usuários.'
                : 'Conclua a configuração do portal, da chave Ed25519 e habilite a sincronização. O agendador é preparado automaticamente pelo plugin.' ?></p>
            <?php if ($automaticError !== ''): ?><p class="cif-small cif-import-error">Último aviso automático: <?= esc_html($automaticError) ?></p><?php endif; ?>
        </div>
        <?php if ($canRun && $canReconcile): ?>
        <form method="post" action="<?= esc_url(home_url('/cif/sincronizacao/sincronizar')) ?>" data-confirm="Executar uma sincronização manual completa agora? Use esta ação apenas para conferência ou contingência.">
            <?php smtt_cif_nonce_field('smtt_cif_sync_now'); ?>
            <button class="cif-button cif-button--secondary cif-button--sync-now" type="submit">Executar agora</button>
        </form>
        <?php endif; ?>
    </section>

    <div class="cif-sync-overview-grid">
        <div class="cif-console-pane">
            <div class="cif-console-pane__head"><h2>Estado operacional</h2></div>
            <dl class="cif-definition-list cif-definition-list--compact">
                <div><dt>Ambiente</dt><dd><?= esc_html((string)$config['environment']) ?></dd></div>
                <div><dt>Portal</dt><dd><code><?= esc_html((string)($config['portal_base_url'] ?: 'não configurado')) ?></code></dd></div>
                <div><dt>Chave ativa</dt><dd><code><?= esc_html((string)($config['active_key']['key_id'] ?? 'nenhuma')) ?></code></dd></div>
                <div><dt>Automação</dt><dd><strong><?= esc_html($automaticLabel) ?></strong></dd></div>
                <div><dt>Último worker automático</dt><dd><?= esc_html((string)($state['ultimo_worker_automatico_em'] ?? '-')) ?></dd></div>
                <div><dt>Último worker OK</dt><dd><?= esc_html((string)($state['ultimo_worker_automatico_ok_em'] ?? '-')) ?></dd></div>
                <div><dt>Próximo envio automático</dt><dd><?= esc_html($nextQueue) ?></dd></div>
                <div><dt>Próxima reconciliação</dt><dd><?= esc_html($nextReconcile) ?></dd></div>
                <div><dt>Último sucesso</dt><dd><?= esc_html((string)($state['ultimo_sucesso_em'] ?? '-')) ?></dd></div>
                <div><dt>Comunicação verificada</dt><dd><?= esc_html((string)($state['ultimo_heartbeat_ok_em'] ?? '-')) ?></dd></div>
                <div><dt>Última conferência</dt><dd><?= esc_html((string)($state['ultima_reconciliacao_em'] ?? '-')) ?></dd></div>
                <div><dt>Falhas consecutivas</dt><dd><?= (int)($state['falhas_consecutivas'] ?? 0) ?></dd></div>
                <div><dt>Portal plugin/schema</dt><dd><?= esc_html((string)($state['remote_plugin_version'] ?? '-')) ?> / <?= esc_html((string)($state['remote_schema_version'] ?? '-')) ?></dd></div>
            </dl>
        </div>

        <div class="cif-console-pane cif-console-pane--divided">
            <div class="cif-console-pane__head"><h2>Como funciona</h2></div>
            <ol class="cif-rule-list cif-sync-flow-list">
                <li>Uma alteração relevante grava somente um evento local na outbox; a tela do usuário não espera o portal responder.</li>
                <li>O worker automático processa pequenos lotes da fila aproximadamente a cada minuto e respeita a política de retry.</li>
                <li>O heartbeat é enviado automaticamente quando o intervalo configurado vence.</li>
                <li>A cada 15 minutos, a reconciliação verifica até 200 emissões por vez, avança um cursor e corrige apenas divergências reparáveis.</li>
            </ol>
            <p class="cif-small cif-muted">A reconstrução completa continua manual e excepcional. O WP-Cron dispara as rotinas em segundo plano; em produção, um cron real do servidor pode acionar o wp-cron.php para cadência mais rigorosa.</p>
        </div>
    </div>

    <details class="cif-console-section" <?= (empty($config['portal_base_url']) || empty($config['active_key']) || empty($config['enabled'])) ? 'open' : '' ?>>
        <summary><span><strong>Configuração técnica</strong><small>Normalmente configurada apenas uma vez pelo Administrador CIF.</small></span><span class="cif-badge"><?= !empty($config['enabled']) ? 'Ativa' : 'Pendente' ?></span></summary>
        <div class="cif-console-section__body">
            <?php if ($canConfigure): ?>
            <form class="cif-form cif-form--compact" method="post" action="<?= esc_url(home_url('/cif/sincronizacao/configurar')) ?>">
                <?php smtt_cif_nonce_field('smtt_cif_sync_config'); ?>
                <label class="cif-field"><span>URL base do WordPress público</span><input type="text" name="portal_base_url" value="<?= esc_attr((string)$config['portal_base_url']) ?>" placeholder="https://portal.exemplo.gov.br"><small>Os endpoints /wp-json/smtt-cif/v1/sync/* são acrescentados automaticamente.</small></label>
                <div class="cif-form-grid cif-form-grid--3">
                    <label class="cif-field"><span>Ambiente</span><select name="environment"><option value="homologation" <?= selected($config['environment'], 'homologation', false) ?>>Homologação</option><option value="production" <?= selected($config['environment'], 'production', false) ?>>Produção</option></select></label>
                    <label class="cif-field"><span>Intervalo de verificação (s)</span><input type="text" name="heartbeat_interval" value="<?= esc_attr((string)$config['heartbeat_interval']) ?>"></label>
                    <label class="cif-checkbox cif-checkbox--boxed"><input type="checkbox" name="enabled" value="1" <?= checked(!empty($config['enabled']), true, false) ?>><span><strong>Habilitar sincronização</strong><small>Somente após configurar o portal.</small></span></label>
                </div>
                <div class="cif-form-actions"><button class="cif-button cif-button--primary" type="submit">Salvar configuração</button></div>
            </form>
            <?php endif; ?>
        </div>
    </details>

    <details class="cif-console-section" <?= empty($config['active_key']) ? 'open' : '' ?>>
        <summary><span><strong>Chaves Ed25519</strong><small>A chave privada permanece somente na intranet.</small></span><span class="cif-badge"><?= count($config['keys'] ?? []) ?> chave(s)</span></summary>
        <div class="cif-console-section__body">
            <?php if ($canConfigure): ?><form method="post" action="<?= esc_url(home_url('/cif/sincronizacao/chaves/gerar')) ?>" class="cif-inline-form"><?php smtt_cif_nonce_field('smtt_cif_sync_key_generate'); ?><button class="cif-button cif-button--secondary" type="submit">Gerar chave candidata</button></form><?php endif; ?>
            <div class="cif-table-wrap cif-table-wrap--embedded"><table class="cif-table"><thead><tr><th>Key ID</th><th>Status</th><th>Fingerprint SHA-256</th><th>Chave pública Base64</th><th>Ação</th></tr></thead><tbody>
            <?php if (empty($config['keys'])): ?><tr><td class="cif-empty" colspan="5">Nenhuma chave criada.</td></tr><?php endif; ?>
            <?php foreach ($config['keys'] as $key): ?><tr><td><code><?= esc_html((string)$key['key_id']) ?></code></td><td><span class="cif-badge <?= $key['status']==='ATIVA' ? 'cif-badge--success' : 'cif-badge--warning' ?>"><?= esc_html((string)$key['status']) ?></span></td><td><code><?= esc_html((string)$key['fingerprint_hex']) ?></code></td><td><textarea class="cif-sync-public-key" readonly rows="2"><?= esc_textarea((string)($key['public_key_base64'] ?? '')) ?></textarea></td><td><?php if ($canConfigure && $key['status'] !== 'ATIVA'): ?><form method="post" action="<?= esc_url(home_url('/cif/sincronizacao/chaves/ativar')) ?>" data-confirm="A chave pública já foi cadastrada no portal público?"><?php smtt_cif_nonce_field('smtt_cif_sync_key_activate'); ?><input type="hidden" name="key_id" value="<?= esc_attr((string)$key['key_id']) ?>"><button class="cif-button cif-button--secondary" type="submit">Ativar</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <p class="cif-small cif-muted">Keyring privado: <code><?= esc_html((string)$config['keyring_path']) ?></code></p>
        </div>
    </details>

    <details class="cif-console-section">
        <summary><span><strong>Ferramentas avançadas</strong><small>Use somente em diagnóstico ou recuperação orientada.</small></span><span class="cif-badge">Administrador</span></summary>
        <div class="cif-console-section__body">
            <div class="cif-alert cif-alert--warning">A rotina diária é automática. Os comandos abaixo existem somente para diagnóstico, conferência imediata ou recuperação técnica.</div>
            <div class="cif-inline-actions cif-console-actions">
                <?php if ($canRun): ?><form method="post" action="<?= esc_url(home_url('/cif/sincronizacao/executar')) ?>"><?php smtt_cif_nonce_field('smtt_cif_sync_run'); ?><button class="cif-button cif-button--secondary" type="submit">Executar somente fila</button></form><form method="post" action="<?= esc_url(home_url('/cif/sincronizacao/heartbeat')) ?>"><?php smtt_cif_nonce_field('smtt_cif_sync_heartbeat'); ?><button class="cif-button cif-button--secondary" type="submit">Testar comunicação</button></form><?php endif; ?>
                <?php if ($canReconcile): ?><form method="post" action="<?= esc_url(home_url('/cif/sincronizacao/reconciliar')) ?>"><?php smtt_cif_nonce_field('smtt_cif_sync_reconcile'); ?><button class="cif-button cif-button--secondary" type="submit">Reconciliar somente</button></form><?php endif; ?>
                <?php if ($canRebuild): ?><form method="post" action="<?= esc_url(home_url('/cif/sincronizacao/reconstruir')) ?>" data-confirm="Esta operação reenfileira todas as projeções públicas. Continuar?"><?php smtt_cif_nonce_field('smtt_cif_sync_rebuild'); ?><button class="cif-button cif-button--danger" type="submit">Reconstruir tudo</button></form><?php endif; ?>
            </div>
        </div>
    </details>

    <details class="cif-console-section">
        <summary><span><strong>Fila recente</strong><small>Histórico técnico dos eventos enviados ao portal.</small></span><span class="cif-badge"><?= count($events) ?> evento(s)</span></summary>
        <div class="cif-console-section__body cif-console-section__body--table">
            <div class="cif-list-scroll cif-list-scroll--queue"><table class="cif-table cif-table--sync"><thead><tr><th>Evento</th><th>Status</th><th>Tentativas</th><th>Criado</th><th>Última tentativa</th><th>Resultado</th><th>Ação</th></tr></thead><tbody>
            <?php if (!$events): ?><tr><td class="cif-empty" colspan="7">A fila ainda não possui eventos.</td></tr><?php endif; ?>
            <?php foreach ($events as $event): ?><tr><td><code><?= esc_html((string)$event['event_uuid']) ?></code><br><small><?= esc_html((string)$event['event_type']) ?> · v<?= (int)$event['aggregate_version'] ?></small></td><td><span class="cif-badge cif-sync-status--<?= esc_attr(strtolower((string)$event['status'])) ?>"><?= esc_html((string)$event['status']) ?></span></td><td><?= (int)$event['tentativas'] ?></td><td><?= esc_html((string)$event['criado_em']) ?></td><td><?= esc_html((string)($event['ultima_tentativa_em'] ?? '-')) ?></td><td><?= esc_html((string)($event['ultimo_resultado'] ?? $event['ultimo_erro_codigo'] ?? '-')) ?><?php if (!empty($event['ultimo_erro_resumo'])): ?><div class="cif-import-error"><?= esc_html((string)$event['ultimo_erro_resumo']) ?></div><?php endif; ?></td><td><?php if ($canRun && in_array($event['status'], ['ATTENTION_REQUIRED','RETRY'], true)): ?><form method="post" action="<?= esc_url(home_url('/cif/sincronizacao/eventos/' . rawurlencode((string)$event['event_uuid']) . '/reenviar')) ?>"><?php smtt_cif_nonce_field('smtt_cif_sync_requeue_' . $event['event_uuid']); ?><button class="cif-button cif-button--secondary" type="submit">Reenfileirar</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
    </details>
</section>
<?php require SMTT_CIF_GESTAO_PATH . 'templates/layout/footer.php'; ?>
