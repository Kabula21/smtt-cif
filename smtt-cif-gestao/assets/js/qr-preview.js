(() => {
  'use strict';

  const renderQr = (el) => {
    if (!el || !window.SmttQr) return;
    const url = el.getAttribute('data-url');
    if (!url) return;
    try {
      el.innerHTML = window.SmttQr.svg(url, { level: 'M', quiet: 3 });
      el.classList.add('is-rendered');
    } catch (_) {
      el.innerHTML = '<span class="cif-qr-preview__error">Não foi possível renderizar o QR nesta tela.</span>';
    }
  };

  document.querySelectorAll('[data-cif-qr-preview]').forEach(renderQr);
})();
