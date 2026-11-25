# SMTT CIF - Gestão 1.5.0

## Objetivo

A versão 1.5.0 simplifica a operação pós-emissão e corrige a semântica do código da CIF.

## Ciclo individual por agente

O código mantém o formato `AJU##SEBR@@@@`:

- `##`: ciclo individual do agente, reservado automaticamente na criação de cada novo processo de emissão;
- `@@@@`: sequencial global das CIFs, compartilhado por todos os agentes e continuamente crescente.

Exemplo de sequência global:

- agente A, primeiro processo: `AJU01SEBR0001`;
- agente B, primeiro processo: `AJU01SEBR0002`;
- agente C, primeiro processo: `AJU01SEBR0003`;
- agente A, segundo processo: `AJU02SEBR0004`.

Ocorrência, recuperação, recolhimento e inutilização não incrementam o ciclo. Um novo processo incrementa o ciclo do agente. O operador não digita o ciclo.

A migration `20260924_027_agent_cycles` adiciona o controle individual por agente e preserva, nas emissões históricas, os dois dígitos de ciclo já gravados no próprio código emitido. O sequencial global existente não é renumerado.

## Pós-emissão integrado

`Ocorrências` e `Recolhimentos` deixam de aparecer como módulos operacionais independentes no menu. A emissão passa a concentrar:

- ocorrência da CIF;
- recuperação;
- recolhimento;
- inutilização;
- histórico técnico e mudanças de estado.

As telas de detalhe desses registros continuam existindo para permitir ações específicas, documentos, auditoria e navegação a partir de Relatórios.

As visões globais de Ocorrências e Recolhimentos permanecem em **Relatórios** para análise gerencial, filtros e exportação.

## Ficha do agente

A ficha deixa de repetir uma tabela independente de ocorrências. A visão operacional do agente mantém processos e emissões; a história pós-emissão é consultada na emissão correspondente.

## Compatibilidade

- não remove emissões, ocorrências ou recolhimentos existentes;
- não altera códigos CIF históricos;
- mantém o sequencial final global existente;
- mantém as tabelas legadas de ciclo global apenas por compatibilidade de schema/histórico, sem expor abertura manual de ciclo na operação;
- o descarte de cadastro antes da primeira emissão também remove o contador de ciclo preparatório daquele agente.

## Homologação recomendada

1. atualizar a instalação de homologação;
2. confirmar execução da migration `20260924_027_agent_cycles`;
3. abrir um novo processo para um agente sem processos e confirmar ciclo `01`;
4. emitir uma CIF e conferir o próximo sequencial global;
5. registrar uma ocorrência nessa emissão e confirmar que o ciclo não muda;
6. abrir novo processo para o mesmo agente e confirmar ciclo `02`;
7. emitir novamente e confirmar que o trecho final continua do próximo sequencial global disponível;
8. confirmar que Ocorrências/Recolhimentos não aparecem mais no menu operacional e continuam disponíveis em Relatórios.
