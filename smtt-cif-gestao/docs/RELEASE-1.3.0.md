# SMTT CIF - Gestão 1.3.0

## Escopo

Revisão de UX das listagens operacionais e redução do custo de abertura das páginas.

### Listagens

- filtros simplificados em Agentes, Processos, Emissões, Ocorrências e Recolhimentos;
- filtros de data passam a representar uma única data quando isso é suficiente para a operação;
- ordenação clicável diretamente nos títulos das colunas;
- quantidade por página no rodapé, junto à paginação;
- paginação compacta, sem renderizar centenas de links quando houver muitas páginas;
- tabela com cabeçalho fixo e rolagem interna quando o conteúdo exceder o espaço disponível;
- mesmo padrão visual e de interação entre os cinco módulos.

### Desempenho

- controllers e árvores de serviços são materializados somente quando a rota correspondente é acessada;
- requests WordPress fora de `/cif` não abrem a conexão dedicada com `smtt_cif` apenas por o plugin estar ativo;
- perfis e permissões efetivos do usuário CIF são resolvidos em uma única consulta de autorização;
- contagens de Emissões, Ocorrências e Recolhimentos evitam joins não necessários;
- busca da versão biográfica corrente deixa de usar agregação global `MAX/GROUP BY` nas listagens principais e passa a usar o índice `(agente_id, versao)`;
- migration `20260924_026_list_performance` adiciona índices específicos para filtros, datas e ordenações mais frequentes.

Nenhuma regra de emissão, numeração, biometria, QR, sincronização, auditoria ou validade da CIF foi alterada.
