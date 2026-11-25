# Release 1.1.2

## Descarte administrativo pré-emissão

- Permite remover definitivamente um agente enquanto não existir registro em `emissoes_cif` para ele.
- A remoção abrange coletas/biometrias, validações biográficas e processos/documentos/eventos preparatórios.
- Itens de importação preservam o histórico do lote, mas deixam de apontar para o agente removido.
- Arquivos privados vinculados à preparação são removidos apenas quando ficarem órfãos.
- Exige `cif.agente.excluir`, nonce, justificativa de 10 a 500 caracteres e gera auditoria `AGENTE_CADASTRO_DESCARTADO_PRE_EMISSAO`.
- A existência de qualquer CIF emitida bloqueia definitivamente esse descarte.
- Não há migration nova.
