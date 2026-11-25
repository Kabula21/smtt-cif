# SMTT CIF - Gestão 1.12.0

## Desempenho e percepção de resposta

- Adiciona indicador global de progresso para navegação, pesquisas e formulários.
- O indicador tem atraso de 180 ms: operações rápidas não exibem animação; operações lentas mostram feedback imediato ao usuário.
- Mantém o carregamento contextual dos modais sem recarregar a página inteira.
- Otimiza a contagem da listagem de agentes quando os filtros não dependem dos dados biográficos, evitando joins e anti-join desnecessários.
- Mantém os índices de listagem introduzidos na migração `20260924_026_list_performance`.

## Compatibilidade

- Sem alteração de contrato com o plugin público de validação.
- Sem alteração de schema nesta versão.
