# SMTT CIF - Gestão 1.17.14 — Exportação para gráfica

- Base: ZIP enviado pelo usuário `smtt-cif-gestao(7).zip`, mantendo as artes PNG originais da frente e do verso.
- Nova ação na pré-visualização e tela de impressão: **Exportar PDF para gráfica (CMYK + sangria 1 mm)**.
- Exportação local no navegador (não envia dados a terceiros): duas páginas (frente e verso), rasterização a 600 DPI de dados, fotografia, QR Code e assinaturas.
- PDF com imagens `/DeviceCMYK` de 8 bits/componente, `MediaBox` e `BleedBox` de 87,60 × 55,98 mm e `TrimBox` de 85,60 × 53,98 mm, recuado 1 mm de cada lado.
- Fundo da arte original mantém o tamanho CR80; a sangria prolonga exclusivamente os pixels de borda para fora da linha de corte, sem deslocar o conteúdo.
- Arte original RGB convertida para CMYK por aproximação matemática, **sem perfil ICC da gráfica**. É indispensável uma prova de cor e de corte na gráfica; não se garante correspondência exata com as cores da tela. Esta saída não é PDF/X.
- Exportador é incorporado somente à resposta HTML de leitura, preservando os artefatos HTML imutáveis e as regras de impressão existentes.
- Impressão normal do navegador, fluxo de emissão, termo assinado, banco e plugin de Validação continuam inalterados.
- **Atenção**: a arte está em aproximadamente 285 DPI nativos. Exportar em 600 DPI melhora a nitidez de dados e QR Code, mas não aumenta a resolução intrínseca do PNG de fundo ou de fotografias de origem inferior.
