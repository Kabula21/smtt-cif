# SMTT CIF – Gestão 1.17.16 — CMYK com reprodução corrigida do verde

Base: ZIP fornecido pelo usuário SMTT-CIF-Gestao-1.17.15-CMYK-Sangria-Corte-Marcado(3).zip.

- Ajuste do conversor que gerava C100 M0 Y13 K85 e aspecto azulado nas áreas verdes.
- ICC de saída genérico Artifex CMYK SWOP, incorporado ao PDF nas imagens CMYK via /ICCBased (4 canais), sem envio de dados a servidores externos.
- Tabela 3D sRGB→CMYK pré-calculada com LittleCMS e interpolação tetraédrica no navegador.
- Correção seletiva de verde profundo para aproximar a referência de cor (C90 M57 Y71 K72); não altera arte RGB original, foto, QR ou dados salvos.
- Frente e verso em duas páginas PDF em até 600 DPI; CR80 com 1 mm sangria e marcas de corte externas, mantendo /TrimBox e /BleedBox.
- Prévia e impressão comum inalteradas. Plugins externos, schema, chaves e conexões inalterados.

**Limitação:** este perfil SWOP é genérico. Uma equivalência exata com a impressora PVC ou a gráfica exige o perfil ICC do processo específico, prova física e ajuste de cor aprovado. O arquivo não se declara certificado PDF/X.
