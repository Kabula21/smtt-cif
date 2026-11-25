# SMTT CIF – Gestão 1.17.15 — Sangria profissional com marcas de corte

Base: SMTT CIF – Gestão 1.17.14, derivado do ZIP final do usuário `smtt-cif-gestao(7).zip`.

- Exportar PDF para gráfica em duas páginas (frente e verso), CMYK /DeviceCMYK 600 DPI.
- Tamanho de corte CR80: **85,60 × 53,98 mm**, registrado em `/TrimBox`.
- Sangria **1 mm por lado**: imagem colorida 87,60 × 55,98 mm, registrada em `/BleedBox`.
- Marcas de corte finas em preto 100% K, inteiramente FORA da área de sangria, visíveis no PDF técnico.
- Papel externo (slug) de 3 mm em cada lado para posicionar as marcas; por isso `/MediaBox` mede **93,60 × 61,98 mm**. Papel externo e marcas são descartados na refilagem.
- Sangria formada por reflexão de aproximadamente 1 mm das bordas da arte original, evitando as faixas monocromáticas resultantes da extensão de uma única linha de pixels.
- Pré-visualização e impressão normal pelo navegador **não foram modificadas**. O PDF técnico só é gerado ao clicar no botão Exportar para gráfica.
- Nenhum arquivo de arte enviado pelo usuário foi alterado. Não há modificação do HTML histórico criptografado, da emissão, do banco nem do plugin externo.
- Conversão RGB → CMYK é matemática, sem perfil ICC; **não é PDF/X** e a cor final depende da gráfica. Enviar prova de cor/prova de corte antes de lotes.
- 600 DPI é resolução de saída; não amplia a definição real de fotografias ou fundos originais.
