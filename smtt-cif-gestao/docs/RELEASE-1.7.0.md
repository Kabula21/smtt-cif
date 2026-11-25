# SMTT CIF - Gestão 1.7.0

## Objetivo

Reduzir a ambiguidade na tramitação dos processos e tornar a conferência de dados uma etapa visualmente ligada à aprovação da emissão.

## Alterações

- remove o card independente **Conferência dos dados** da página principal do processo;
- mantém na página apenas um indicador de conferência com data, responsável e método;
- **Aprovar e liberar emissão** passa a exibir a fotografia biométrica e o conjunto cadastral utilizado como base da CIF;
- CPF é exibido somente para perfis com `cif.agente.visualizar_dados_sensiveis`;
- a fotografia é exibida somente para perfis com `cif.biometria.visualizar`;
- aprovação e conferência continuam sendo registradas separadamente na auditoria, mesmo quando executadas no mesmo ato;
- **Registrar pendência** passa a solicitar tipo, descrição e prazo opcional de regularização;
- **Indeferir processo** passa a solicitar motivo e fundamentação;
- **Cancelar processo** passa a solicitar motivo administrativo e justificativa;
- eventos de tramitação preservam `motivo_codigo`, rótulo do motivo e prazo de regularização quando aplicável;
- quando o processo está pendente, a pendência atual é resumida na própria página;
- a coleta biométrica apresentada na conferência exige fotografia e assinatura válidas/conformes;
- não há migration nova nesta versão.

## Compatibilidade

Os códigos e estados persistidos anteriormente foram preservados. `PENDENTE_DOCUMENTACAO`, `INDEFERIDO` e `CANCELADO` continuam sendo os mesmos estados internos; a mudança é de UX e de metadados registrados nos eventos/auditoria.
