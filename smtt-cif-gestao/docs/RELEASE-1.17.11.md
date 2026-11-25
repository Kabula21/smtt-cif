# SMTT CIF - Gestão 1.17.11 — Termo de entrega

- Mantém os ajustes de apresentação da versão 1.17.10: texto do login, relatórios e caixa alta na arte CR80.
- Adiciona impressão do termo institucional A4 na etapa de entrega, em nova aba, com a marca oficial colorida SMTT.
- Preenche a declaração em texto corrido com os dados do snapshot da emissão (nome, CPF, matrícula, código, tipo e data de emissão).
- O CPF é aberto somente após autorização de `EMISSAO_ENTREGAR`, nunca enviado ao portal público nem exposto no URL.
- Mantém em branco a data do recebimento para preenchimento quando o agente assinar fisicamente.
- Não altera as regras de ativação, entrega, sync, dados ou tabelas do banco.
- A digitalização/anexação do termo assinado e a obrigatoriedade desse anexo ainda dependem de definição do fluxo de entrega.

## Homologação

Todos os 177 arquivos PHP passaram em `php -l`. A suíte legada `tests/run.php` registrou 7 falhas tanto na base 1.17.10 quanto nesta versão (não há falhas adicionais introduzidas por esta alteração). Testar no ambiente local antes de usar em produção.
