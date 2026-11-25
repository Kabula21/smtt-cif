# Release 1.1.0

Atualização do SMTT CIF - Gestão focada em arte física da CIF, pré-visualização e tratamento de imagens.

## Alterações

- nova arte institucional CR80 em PNG para frente e verso (`CIF-CR80-2026-02`);
- campos dinâmicos reposicionados sobre a arte real;
- assinaturas do agente e do signatário preservam transparência e usam composição visual sem caixa branca;
- nova rota de pré-visualização da carteira, sem abertura de tentativa de impressão;
- remoção dos campos e da regra obrigatória de 500 DPI na coleta biométrica;
- validação de qualidade por dimensões em pixels;
- otimização automática de foto/assinatura quando a imagem for excessivamente grande e houver editor WordPress disponível;
- PNG de assinatura é mantido como PNG, preservando canal alfa;
- nenhuma migration nova e nenhuma alteração de schema.

## Compatibilidade

- banco e migrations permanecem 001 a 024;
- sincronização com o SMTT CIF - Validação não foi alterada;
- artefatos históricos já produzidos permanecem imutáveis; novas artes utilizam o template `CIF-CR80-2026-02`.
