            </div>
        </main>
<?php if (($currentUser ?? null) !== null && !(isset($_GET['embedded']) && (string) $_GET['embedded'] === '1')): ?>
    </div>
</div>
<?php endif; ?>

<div class="cif-page-progress" data-cif-page-progress hidden aria-live="polite" aria-busy="true">
    <div class="cif-page-progress__backdrop"></div>
    <div class="cif-page-progress__card" role="status">
        <span class="cif-page-progress__spinner" aria-hidden="true"></span>
        <div><strong data-cif-page-progress-title>Carregando...</strong><small>Aguarde um instante.</small></div>
    </div>
</div>

<div class="cif-confirm-modal" data-cif-confirm hidden>
    <div class="cif-confirm-modal__backdrop"></div>
    <div class="cif-confirm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="cif-confirm-title">
        <h2 id="cif-confirm-title" data-cif-confirm-title>Confirmar ação</h2>
        <p data-cif-confirm-message>Deseja continuar?</p>
        <div class="cif-confirm-modal__actions">
            <button type="button" class="cif-button cif-button--secondary" data-cif-confirm-cancel>Cancelar</button>
            <button type="button" class="cif-button cif-button--danger" data-cif-confirm-accept>Confirmar</button>
        </div>
    </div>
</div>

<div class="cif-modal cif-modal--remote" id="cif-frame-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="cif-frame-modal-title">
    <div class="cif-modal__backdrop" data-modal-close></div>
    <div class="cif-modal__dialog cif-modal__dialog--wide cif-modal__dialog--remote" role="document">
        <div class="cif-modal__head">
            <div>
                <span class="cif-modal__eyebrow">Fluxo contextual</span>
                <h2 id="cif-frame-modal-title">Detalhes do módulo</h2>
                <p data-frame-modal-subtitle>Navegação contextual sem sair da tela atual.</p>
            </div>
            <button type="button" class="cif-modal__close" data-modal-close aria-label="Fechar">×</button>
        </div>
        <div class="cif-modal__body cif-modal__body--remote" data-remote-modal-body>
            <div class="cif-remote-loading" role="status"><span class="cif-remote-loading__spinner"></span><strong>Carregando...</strong></div>
        </div>
    </div>
</div>
</body>
</html>
