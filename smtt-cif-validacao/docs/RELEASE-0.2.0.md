# Release 0.2.0

Versão de homologação local do **SMTT CIF - Validação**, preparada para coexistir com o **SMTT CIF - Gestão 0.19.0** no mesmo WordPress durante os testes ponta a ponta.

Principais alterações sobre 0.1.0:

- chave operacional **LOCAL / HOMOLOGAÇÃO ↔ PRODUÇÃO**;
- LOCAL mapeia automaticamente para `homologation`;
- PRODUÇÃO mapeia automaticamente para `production`;
- mudança inicial para Produção exige confirmação explícita e HTTPS válido nas URLs do WordPress;
- página pública exibe aviso visível de homologação quando o modo não é Produção;
- heartbeat informa o ambiente criptográfico e o modo operacional;
- Key IDs `smtt-cif-hml-*` e `smtt-cif-prod-*` são protegidos contra uso no ambiente oposto;
- migration `20260921_002_runtime_mode` preserva atualização a partir de instalações 0.1.0;
- nenhuma alteração foi feita no plugin Gestão de referência.

## Uso local previsto

No mesmo WordPress local:

1. Gestão configurado como `homologation`;
2. Validação configurado como **LOCAL / HOMOLOGAÇÃO**;
3. URL base do portal no Gestão apontando para a própria URL local do WordPress;
4. chave pública `smtt-cif-hml-*` do Gestão instalada no Validação;
5. worker de sincronização habilitado somente após heartbeat bem-sucedido.

Em produção, o Validação deve ser instalado no WordPress público separado e alterado para **PRODUÇÃO** somente depois que HTTPS e a chave `prod` estiverem preparados.
