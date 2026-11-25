(function () {
    'use strict';

    const body = document.body;
    const shell = document.querySelector('[data-cif-shell]');
    const isEmbedded = body.classList.contains('cif-embedded');
    const remoteModal = document.getElementById('cif-frame-modal');
    const remoteBody = remoteModal ? remoteModal.querySelector('[data-remote-modal-body]') : null;
    const remoteTitle = remoteModal ? remoteModal.querySelector('#cif-frame-modal-title') : null;
    const remoteSubtitle = remoteModal ? remoteModal.querySelector('[data-frame-modal-subtitle]') : null;
    const remoteDialog = remoteModal ? remoteModal.querySelector('.cif-modal__dialog') : null;
    let remoteModalDirty = false;
    let remoteModalCurrentUrl = '';

    const normalizeEmbeddedUrl = (input) => {
        try {
            const url = new URL(input, window.location.href);
            if (url.origin === window.location.origin && url.pathname.indexOf('/cif/') !== -1) {
                url.searchParams.set('embedded', '1');
            }
            return url.toString();
        } catch (_error) {
            return input;
        }
    };

    const openModal = (modal) => {
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        body.classList.add('cif-modal-open');
    };

    const closeModal = (modal) => {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.cif-modal.is-open')) {
            body.classList.remove('cif-modal-open');
        }
        if (modal === remoteModal) {
            if (remoteBody) remoteBody.innerHTML = '<div class="cif-remote-loading" role="status"><span class="cif-remote-loading__spinner"></span><strong>Carregando...</strong></div>';
            remoteModalCurrentUrl = '';
            if (remoteModalDirty) {
                remoteModalDirty = false;
                window.location.reload();
            }
        }
    };

    const extractEmbeddedContent = (html) => {
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const embedded = parsed.querySelector('.cif-content--embedded');
        const standard = parsed.querySelector('.cif-content');
        const source = embedded || standard || parsed.body;
        const title = parsed.querySelector('title')?.textContent || '';
        return { html: source.innerHTML, title };
    };

    const remoteError = (message) => {
        if (!remoteBody) return;
        remoteBody.innerHTML = '<div class="cif-remote-error"><strong>Não foi possível abrir esta operação.</strong><p></p></div>';
        const p = remoteBody.querySelector('p');
        if (p) p.textContent = message || 'Tente novamente ou atualize a página.';
    };

    const loadRemoteModal = async (url, options = {}) => {
        if (!remoteModal || !remoteBody || !remoteDialog) return;
        const method = (options.method || 'GET').toUpperCase();
        const targetUrl = normalizeEmbeddedUrl(url);
        remoteModalCurrentUrl = targetUrl;
        remoteBody.innerHTML = '<div class="cif-remote-loading" role="status"><span class="cif-remote-loading__spinner"></span><strong>Carregando...</strong></div>';
        try {
            const response = await fetch(targetUrl, {
                method,
                body: method === 'GET' ? undefined : options.body,
                credentials: 'same-origin',
                redirect: 'follow',
                headers: {
                    'X-SMTT-CIF-Modal': '1'
                }
            });
            const text = await response.text();
            if (!response.ok && response.status >= 500) {
                remoteError('O servidor retornou um erro ao processar a operação.');
                return;
            }
            const extracted = extractEmbeddedContent(text);
            remoteBody.innerHTML = extracted.html;
            remoteModalCurrentUrl = normalizeEmbeddedUrl(response.url || targetUrl);
            if (extracted.title && remoteTitle && !options.keepTitle) {
                const cleaned = extracted.title.replace(/\s+—\s+SMTT CIF.*$/i, '').trim();
                if (cleaned) remoteTitle.textContent = cleaned;
            }
            if (method !== 'GET') remoteModalDirty = true;
            bindDynamicMasks(remoteBody);
        } catch (_error) {
            remoteError('Falha de comunicação com o Sistema CIF.');
        }
    };

    const openRemoteModal = (trigger) => {
        if (!remoteModal || !remoteDialog) return;
        const href = trigger.getAttribute('href') || trigger.getAttribute('data-frame-src');
        if (!href) return;
        remoteDialog.classList.remove('cif-modal__dialog--compact', 'cif-modal__dialog--wide', 'cif-modal__dialog--full');
        const size = trigger.getAttribute('data-frame-size') || 'wide';
        if (size === 'compact') remoteDialog.classList.add('cif-modal__dialog--compact');
        else if (size === 'full') remoteDialog.classList.add('cif-modal__dialog--full');
        else remoteDialog.classList.add('cif-modal__dialog--wide');
        if (remoteTitle) remoteTitle.textContent = trigger.getAttribute('data-frame-title') || trigger.textContent.trim() || 'Detalhes do módulo';
        if (remoteSubtitle) remoteSubtitle.textContent = trigger.getAttribute('data-frame-subtitle') || 'Navegação contextual sem sair da tela atual.';
        remoteModalDirty = false;
        openModal(remoteModal);
        loadRemoteModal(href, { keepTitle: true });
    };

    document.addEventListener('click', (event) => {
        const openTrigger = event.target.closest('[data-modal-open]');
        if (openTrigger) {
            const modal = document.getElementById(openTrigger.getAttribute('data-modal-open'));
            if (modal) {
                event.preventDefault();
                const current = openTrigger.closest('.cif-modal.is-open');
                if (current && current !== modal && current !== remoteModal) closeModal(current);
                openModal(modal);
            }
            return;
        }

        const frameTrigger = event.target.closest('[data-frame-modal]');
        if (frameTrigger) {
            event.preventDefault();
            openRemoteModal(frameTrigger);
            return;
        }

        // Modalização somente quando a ação estiver marcada explicitamente com
        // data-frame-modal. Links utilitários (Limpar, Cancelar, paginação,
        // exportação etc.) mantêm o comportamento normal do navegador.

        const remoteLink = event.target.closest('#cif-frame-modal a[href]');
        if (remoteLink && remoteModal?.classList.contains('is-open')) {
            const href = remoteLink.getAttribute('href') || '';
            if (href !== '' && !href.startsWith('#') && !remoteLink.hasAttribute('download') && remoteLink.target !== '_blank') {
                try {
                    const parsed = new URL(href, window.location.href);
                    if (parsed.origin === window.location.origin && parsed.pathname.indexOf('/cif/') !== -1) {
                        event.preventDefault();
                        loadRemoteModal(href);
                        return;
                    }
                } catch (_error) {}
            }
        }

        const closeTrigger = event.target.closest('[data-modal-close]');
        if (closeTrigger) closeModal(closeTrigger.closest('.cif-modal'));
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!form.closest('#cif-frame-modal')) return;
        if (form.getAttribute('data-confirm') && form.dataset.confirmed !== '1') return;
        event.preventDefault();
        const method = (form.method || 'POST').toUpperCase();
        const action = form.action || remoteModalCurrentUrl || window.location.href;
        if (method === 'GET') {
            const data = new FormData(form);
            const url = new URL(action, window.location.href);
            data.forEach((value, key) => url.searchParams.set(key, String(value)));
            loadRemoteModal(url.toString());
            return;
        }
        loadRemoteModal(action, { method, body: new FormData(form) });
    });

    document.addEventListener('click', (event) => {
        const printTrigger = event.target.closest('[data-report-print]');
        if (!printTrigger) return;
        const modal = printTrigger.closest('.cif-report-modal');
        if (!modal) return;
        event.preventDefault();

        // Cria um documento temporário de impressão diretamente no BODY.
        // Isto evita popup e, principalmente, evita que o conteúdo seja
        // ocultado por display/visibility/overflow de ancestrais do modal.
        document.getElementById('cif-report-print-root')?.remove();
        const root = document.createElement('section');
        root.id = 'cif-report-print-root';
        root.setAttribute('aria-hidden', 'true');

        const head = modal.querySelector('.cif-report-print-head');
        const content = modal.querySelector('.cif-report-print-content');
        const meta = modal.querySelector('.cif-report-print-meta');

        const documentHead = document.createElement('header');
        documentHead.className = 'cif-print-document-head';
        documentHead.innerHTML = '<div class="cif-print-brand">SMTT · Sistema CIF</div>';
        if (head) {
            const title = head.querySelector('h2');
            const description = head.querySelector('p');
            if (title) {
                const h1 = document.createElement('h1');
                h1.textContent = title.textContent || 'Relatório';
                documentHead.appendChild(h1);
            }
            if (description) {
                const p = document.createElement('p');
                p.textContent = description.textContent || '';
                documentHead.appendChild(p);
            }
        }
        root.appendChild(documentHead);
        if (meta) root.appendChild(meta.cloneNode(true));
        if (content) root.appendChild(content.cloneNode(true));

        body.appendChild(root);
        body.classList.add('cif-report-printing');

        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => window.print());
        });
    });

    const clearReportPrintState = () => {
        body.classList.remove('cif-report-printing');
        document.getElementById('cif-report-print-root')?.remove();
    };
    window.addEventListener('afterprint', clearReportPrintState);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const activeModal = document.querySelector('.cif-modal.is-open');
            if (activeModal) closeModal(activeModal);
        }
    });

    const confirmRoot = document.querySelector('[data-cif-confirm]');
    if (confirmRoot) {
        const messageNode = confirmRoot.querySelector('[data-cif-confirm-message]');
        const titleNode = confirmRoot.querySelector('[data-cif-confirm-title]');
        const acceptButton = confirmRoot.querySelector('[data-cif-confirm-accept]');
        const cancelButton = confirmRoot.querySelector('[data-cif-confirm-cancel]');
        let pendingAction = null;

        const openConfirm = (message, onAccept, title = 'Confirmar ação', acceptLabel = 'Confirmar') => {
            pendingAction = onAccept;
            if (messageNode) messageNode.textContent = message;
            if (titleNode) titleNode.textContent = title;
            if (acceptButton) acceptButton.textContent = acceptLabel;
            confirmRoot.hidden = false;
            requestAnimationFrame(() => {
                confirmRoot.classList.add('is-visible');
                if (acceptButton) acceptButton.focus();
            });
            body.classList.add('cif-modal-open');
        };

        const closeConfirm = () => {
            confirmRoot.classList.remove('is-visible');
            confirmRoot.hidden = true;
            pendingAction = null;
            if (!document.querySelector('.cif-modal.is-open')) body.classList.remove('cif-modal-open');
        };

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            const message = form.getAttribute('data-confirm');
            if (!message || form.dataset.confirmed === '1') return;
            event.preventDefault();
            event.stopImmediatePropagation();
            openConfirm(message, () => {
                form.dataset.confirmed = '1';
                if (form.closest('#cif-frame-modal')) {
                    const method = (form.method || 'POST').toUpperCase();
                    loadRemoteModal(form.action || remoteModalCurrentUrl, { method, body: new FormData(form) });
                } else {
                    form.submit();
                }
            }, form.getAttribute('data-confirm-title') || 'Confirmar ação', form.getAttribute('data-confirm-label') || 'Confirmar');
        }, true);

        acceptButton && acceptButton.addEventListener('click', () => {
            const action = pendingAction;
            closeConfirm();
            if (typeof action === 'function') action();
        });
        cancelButton && cancelButton.addEventListener('click', closeConfirm);
        confirmRoot.querySelector('.cif-confirm-modal__backdrop')?.addEventListener('click', closeConfirm);
    }

    const sidebarToggle = document.querySelector('[data-cif-sidebar-toggle]');
    const sidebarCollapse = document.querySelector('[data-cif-sidebar-collapse]');
    const sidebarOverlay = document.querySelector('[data-cif-sidebar-overlay]');
    const SIDEBAR_COLLAPSED_KEY = 'smtt-cif-sidebar-collapsed';
    const SIDEBAR_MOBILE_CLASS = 'is-mobile-nav-open';
    const SIDEBAR_COLLAPSED_CLASS = 'is-sidebar-collapsed';

    const applyCollapsedState = (collapsed) => {
        if (!shell) return;
        shell.classList.toggle(SIDEBAR_COLLAPSED_CLASS, collapsed);
        if (sidebarCollapse) {
            sidebarCollapse.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
            sidebarCollapse.setAttribute('title', collapsed ? 'Expandir menu lateral' : 'Recolher menu lateral');
            sidebarCollapse.setAttribute('aria-label', collapsed ? 'Expandir menu lateral' : 'Recolher menu lateral');
        }
    };

    if (shell) {
        const persistedCollapsed = window.localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
        applyCollapsedState(persistedCollapsed);
    }

    sidebarCollapse && sidebarCollapse.addEventListener('click', () => {
        if (!shell) return;
        const next = !shell.classList.contains(SIDEBAR_COLLAPSED_CLASS);
        applyCollapsedState(next);
        window.localStorage.setItem(SIDEBAR_COLLAPSED_KEY, next ? '1' : '0');
    });

    const setMobileNav = (open) => {
        if (!shell) return;
        shell.classList.toggle(SIDEBAR_MOBILE_CLASS, open);
        if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    sidebarToggle && sidebarToggle.addEventListener('click', () => {
        if (!shell) return;
        setMobileNav(!shell.classList.contains(SIDEBAR_MOBILE_CLASS));
    });
    sidebarOverlay && sidebarOverlay.addEventListener('click', () => setMobileNav(false));
    if (window.matchMedia('(max-width: 980px)').matches) setMobileNav(false);

    document.querySelectorAll('[data-cif-auto-submit]').forEach((field) => {
        field.addEventListener('change', () => {
            const form = field.closest('form');
            if (form instanceof HTMLFormElement) form.submit();
        });
    });

    const pageProgress = document.querySelector('[data-cif-page-progress]');
    const pageProgressTitle = pageProgress ? pageProgress.querySelector('[data-cif-page-progress-title]') : null;
    let pageProgressTimer = null;

    const showPageProgress = (title = 'Carregando...') => {
        if (!pageProgress || isEmbedded) return;
        if (pageProgressTitle) pageProgressTitle.textContent = title;
        if (pageProgressTimer !== null) window.clearTimeout(pageProgressTimer);
        // Só aparece quando a navegação realmente demora; em respostas rápidas fica invisível.
        pageProgressTimer = window.setTimeout(() => {
            pageProgress.hidden = false;
            requestAnimationFrame(() => pageProgress.classList.add('is-visible'));
        }, 450);
    };

    const hidePageProgress = () => {
        if (!pageProgress) return;
        if (pageProgressTimer !== null) window.clearTimeout(pageProgressTimer);
        pageProgressTimer = null;
        pageProgress.classList.remove('is-visible');
        pageProgress.hidden = true;
    };

    window.addEventListener('pageshow', hidePageProgress);

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const anchor = event.target.closest('a[href]');
        if (!anchor || anchor.hasAttribute('download') || anchor.target === '_blank' || anchor.hasAttribute('data-frame-modal')) return;
        const href = anchor.getAttribute('href') || '';
        if (href === '' || href.startsWith('#') || href.startsWith('javascript:')) return;
        try {
            const url = new URL(href, window.location.href);
            if (url.origin !== window.location.origin || url.pathname.indexOf('/cif') !== 0) return;
            showPageProgress('Carregando...');
        } catch (_error) {}
    }, true);

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.closest('#cif-frame-modal')) return;
        if (form.getAttribute('data-confirm') && form.dataset.confirmed !== '1') return;
        showPageProgress((form.method || 'GET').toUpperCase() === 'GET' ? 'Pesquisando...' : 'Processando...');
    });

    const productionWatch = document.querySelector('[data-cif-production-watch]');
    if (productionWatch) {
        let productionRefreshQueued = false;
        const handleProductionUpdate = (data) => {
            if (!data || typeof data !== 'object' || data.type !== 'smtt-cif-production-updated') return;
            const expectedUuid = productionWatch.getAttribute('data-emission-uuid') || '';
            if (expectedUuid === '' || data.emissionUuid !== expectedUuid || productionRefreshQueued) return;
            productionRefreshQueued = true;
            window.location.reload();
        };
        window.addEventListener('message', (event) => {
            if (event.origin !== window.location.origin) return;
            handleProductionUpdate(event.data);
        });
        window.addEventListener('storage', (event) => {
            if (event.key !== 'smtt-cif-production-updated' || !event.newValue) return;
            try {
                handleProductionUpdate(JSON.parse(event.newValue));
            } catch (_error) {
                // Ignora payload inválido de armazenamento local.
            }
        });
    }

    const bindDynamicMasks = (_root) => {
        // reservado para máscaras futuras; mantém o carregamento remoto desacoplado.
    };

    if (isEmbedded) {
        document.querySelectorAll('a[href]').forEach((anchor) => {
            const href = anchor.getAttribute('href') || '';
            if (href === '' || href.startsWith('#') || href.startsWith('javascript:')) return;
            anchor.setAttribute('href', normalizeEmbeddedUrl(href));
        });
        document.querySelectorAll('form').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) return;
            const action = form.getAttribute('action') || window.location.href;
            form.setAttribute('action', normalizeEmbeddedUrl(action));
            if (!form.querySelector('input[name="embedded"]')) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'embedded';
                hidden.value = '1';
                form.appendChild(hidden);
            }
        });
    }
})();
