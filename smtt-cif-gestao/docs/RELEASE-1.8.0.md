# SMTT CIF - Gestão 1.8.0

## Objetivo

Simplificar a etapa entre a geração da emissão e a entrega da carteira, eliminando navegação redundante sem perder auditoria de produção.

## Fluxo operacional

O caminho principal passa a ser:

**Gerar CIF → Produzir carteira → Entregar e ativar CIF**

- **Produzir carteira** abre a arte em uma nova aba;
- a aba original da emissão permanece aberta;
- a nova aba oferece **Imprimir / Salvar como PDF**, **Confirmar produção concluída** e **Registrar falha na produção**;
- a confirmação de produção continua registrando `IMPRESSAO_CIF_CONCLUIDA` e `CIF_PRODUZIDA`;
- a situação física passa de `NAO_PRODUZIDA` para `EM_GUARDA_SMTT`;
- a aba original é atualizada automaticamente por mensagem same-origin;
- somente depois da produção a ação **Registrar entrega e ativar CIF** fica disponível.

## Simplificações

- remove **Gerar e visualizar carteira** do caminho obrigatório;
- remove o segundo passo **Abrir arte para imprimir / salvar PDF** da página da emissão;
- a pré-visualização permanece como ação secundária **Visualizar carteira**, em nova aba e sem abrir tentativa de produção;
- o histórico deixa de pedir que o operador registre manualmente o resultado em uma tabela; uma tentativa aberta apenas oferece **Continuar produção**;
- se já existir tentativa `ABERTA`, uma nova solicitação reaproveita a tentativa existente em vez de criar outra;
- uma nova tentativa antes da entrega continua disponível como ação secundária e permanece auditada; não faz parte do caminho obrigatório.

## Interface

A tela da emissão passa a exibir um indicador simples de três etapas:

1. Emissão gerada;
2. Carteira produzida;
3. Entregue e ativada.

O bloco de QR/arte permanece informativo, enquanto a operação fica concentrada no bloco **Produção da carteira**.

## Banco e compatibilidade

Não há migration nova. Tabelas, eventos, artefatos, tentativas de impressão, QR, numeração, sincronização e regras de entrega permanecem compatíveis com as versões anteriores.
