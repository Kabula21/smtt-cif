# SMTT CIF - Gestão 1.4.0

## Escopo

Evolução da área de Relatórios para consulta gerencial navegável, sem duplicar as telas operacionais.

### Relatórios

- navegação direta entre Agentes, Processos, Emissões CIF, Ocorrências, Recolhimentos e Atividade dos usuários;
- filtros enxutos com busca, status quando aplicável e uma única data;
- ordenação clicável nos cabeçalhos;
- quantidade por página e paginação no rodapé padronizado;
- tabela com rolagem interna;
- coluna Ações com abertura do registro original;
- exportação CSV respeita o relatório e os filtros ativos.

### Atividade dos usuários

- exibe usuário, setor, perfil, ação e registro afetado;
- “Ver registro” abre o objeto auditado quando existe rota funcional correspondente;
- “Detalhes” abre modal somente leitura com origem, IP, request ID, justificativa, valores anterior/posterior e estado de integridade do registro.

### Navegação

Registros abertos a partir de Relatórios recebem contexto de retorno validado para a própria rota `/cif/relatorios`, permitindo voltar à consulta filtrada sem perder página, ordenação ou filtros.

Nenhuma regra de emissão, biometria, autorização, sincronização ou validade da CIF foi alterada.
