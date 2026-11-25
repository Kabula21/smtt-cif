## Versão 1.11.0 - relatórios em abas e ações padronizadas

A 1.11.0 reorganiza visualmente a área de **Relatórios** sem alterar suas regras de consulta. Agentes, Processos, Emissões CIF, Ocorrências e Recolhimentos passam a funcionar como abas integradas ao conteúdo, deixando claro que representam categorias de relatório e não ações independentes.

Os indicadores superiores ficam mais compactos, o cabeçalho da tabela passa a concentrar título, quantidade, intervalo e **Exportar CSV**, e a coluna **Ações** usa controles padronizados para abrir o registro original. A paginação, filtros, ordenação, permissões e preservação do contexto de retorno permanecem inalterados.

## Versão 1.10.0 - sincronização automática em segundo plano

A 1.10.0 torna a integração com o portal público automática depois da configuração inicial. Alterações relevantes continuam sendo gravadas na outbox transacional durante a operação local, mas nenhuma chamada HTTP é feita no clique do usuário. Um worker em WP-Cron processa pequenos lotes aproximadamente a cada minuto, executa heartbeat quando o intervalo configurado vence e aplica a política de retry já existente quando o portal está indisponível.

A reconciliação automática ocorre em fatias de até 200 emissões a cada 15 minutos, com cursor persistente para evitar varrer toda a base em cada execução. Um lock MySQL impede sobreposição entre worker e reconciliação. A tela de Sincronização passa a apresentar o estado da automação, última execução, próximos agendamentos e mantém **Executar agora** apenas como contingência administrativa. A reconstrução integral continua exclusivamente manual. O registro dos hooks de cron permanece lazy e não abre o banco `smtt_cif` em páginas WordPress alheias ao Sistema CIF.

## Versão 1.9.0 - perfis e sincronização simplificados

A 1.9.0 libera o módulo de **Relatórios** também para o perfil `EDITOR_CIF`, mantém **Atividade dos usuários** exclusiva do Administrador CIF e restringe a preparação/configuração institucional de emissão ao administrador. A tela de sincronização passa a oferecer **Sincronizar agora** como rotina operacional única: verifica o portal, processa a fila, reconcilia as projeções e reenfileira/entrega apenas divergências reparáveis. Os comandos técnicos individuais permanecem recolhidos em **Ferramentas avançadas**.

## Versão 1.8.0 - produção e ativação simplificadas

A 1.8.0 reduz o pós-emissão ao fluxo operacional **Gerar CIF → Produzir carteira → Entregar e ativar**. A produção abre a carteira em uma nova aba, mantendo a tela da emissão como centro do trabalho. Nessa aba ficam apenas a visualização/ impressão e o registro de **produção concluída** ou **falha**; nenhuma ativação é executada ali.

A confirmação da produção atualiza a aba original automaticamente, libera a entrega quando a CIF passa para **Em guarda na SMTT** e evita a criação de tentativas de produção duplicadas quando já existe uma tentativa aberta. A pré-visualização permanece opcional e sempre abre em outra aba.

## Versão 1.7.0 - revisão de conferência e decisões do processo

A 1.7.0 reorganiza a tramitação para deixar a aprovação como ação principal. A conferência deixa de ocupar um card próprio na página e passa a mostrar, dentro da aprovação, os dados efetivamente relevantes para a CIF: fotografia biométrica, nome, matrícula, CPF conforme permissão, situação funcional, naturalidade, nascimento, tipo sanguíneo, admissão, versão/origem cadastral e coleta biométrica atual. Na página do processo permanece apenas o estado da conferência com responsável, data e método.

Pendência, indeferimento e cancelamento agora possuem formulários semanticamente distintos. A pendência registra categoria, descrição e prazo opcional; o indeferimento exige motivo e fundamentação; o cancelamento exige motivo administrativo e justificativa. Esses metadados são preservados no evento e na auditoria sem alterar o schema do banco.

## Versão 1.6.0 - fluxo de processos simplificado

A 1.6.0 elimina a carência automática de 45 dias para perda, furto e roubo, cria novos processos diretamente em **Em tramitação** e permite ao Administrador CIF registrar a conferência biográfica no mesmo ato de **Aprovar e liberar emissão**. Os estados de pendência documental, indeferimento, cancelamento, execução e conclusão permanecem preservados para rastreabilidade.

# SMTT CIF - Gestão

## Versão 1.5.1 - revisão visual de acesso

A 1.5.1 simplifica a tela de login e a barra superior: remove mensagens técnicas sobre WordPress, atualiza a identificação institucional para **Sistema de CArteira de Identificação FUncional** e remove o atalho permanente de senha da topbar. A troca obrigatória de senha continua preservada no fluxo de segurança.

## Versão 1.5.0 - ciclo por agente e pós-emissão integrado

A 1.5.0 reorganiza o ciclo de vida da CIF para reduzir redundância operacional. **Ocorrências** e **Recolhimentos** deixam de ser módulos principais do menu e passam a ser tratados dentro da própria **Emissão**, em um bloco único de histórico e eventos. As visões globais desses registros permanecem disponíveis em **Relatórios**.

A regra de numeração também passa a refletir o ciclo individual de cada agente: o trecho `##` de `AJU##SEBR@@@@` é reservado automaticamente quando um novo processo é criado para aquele agente; o trecho `@@@@` continua sendo um sequencial global único de todas as CIFs e nunca reinicia. Ocorrências, recuperação, recolhimento e inutilização não incrementam ciclo.

## Versão 1.4.0 - relatórios navegáveis

A 1.4.0 transforma Relatórios em uma área de consulta e navegação: cada tipo de relatório possui filtros enxutos, ordenação por coluna, paginação padronizada e uma coluna de ações que abre o registro original correspondente. O relatório de atividade dos usuários inclui vínculo para o registro afetado quando possível e modal de detalhes da auditoria com valores anterior/posterior, justificativa, origem e integridade.

As telas de detalhe de Agentes, Processos, Emissões, Ocorrências e Recolhimentos preservam o contexto da consulta e oferecem “Voltar ao relatório” quando abertas a partir desta área.

## Versão 1.3.0 - listagens operacionais e desempenho

A 1.3.0 simplifica as listagens de Agentes, Processos, Emissões, Ocorrências e Recolhimentos. Os filtros passam a mostrar apenas os critérios operacionais mais úteis, a ordenação ocorre diretamente nos cabeçalhos das tabelas, e a quantidade por página foi movida para o rodapé junto da paginação. As tabelas mantêm cabeçalho fixo e rolagem interna quando necessário.

A versão também reduz trabalho desnecessário na abertura das páginas: controllers e serviços são carregados sob demanda por rota; o banco `smtt_cif` não é aberto em páginas WordPress alheias ao `/cif`; a resolução de perfis/permissões do usuário foi consolidada; consultas de listagem e contagem foram enxugadas; e novos índices cobrem filtros e ordenações frequentes.

## Versão 1.2.1 - refinamento do fluxo de senha e usuários

A 1.2.1 mantém a autenticação própria introduzida na 1.2.0 e refina a experiência: a troca obrigatória de senha usa a mesma superfície visual do login, sem sidebar, e retorna ao login após a alteração. Os formulários de gestão de usuários foram padronizados visualmente com os componentes do Sistema CIF.

## Versão 1.2.0 - autenticação própria do CIF

A 1.2.0 separa os usuários operacionais do WordPress. Login, senha, e-mail, nome, setor, perfis, sessões e governança de acesso passam a ser mantidos no banco próprio `smtt_cif`; o `wp-admin` permanece apenas para administração técnica da plataforma pela TI. O Administrador CIF gerencia usuários dentro do sistema e a auditoria/relatórios preservam a autoria de cada operação.

## Versão 1.1.5 - data de admissão na projeção pública

A 1.1.5 acrescenta a data de admissão, já congelada no snapshot da emissão, à projeção pública enviada ao SMTT CIF - Validação. O hash de reconciliação passa a cobrir o novo campo e a reconstrução da projeção recalcula o estado desejado antes do reenvio.


## Versão 1.1.2 - descarte controlado antes da primeira emissão

A 1.1.2 permite descartar um cadastro lançado por engano enquanto o agente ainda não possuir nenhuma CIF emitida. A operação exige permissão de exclusão, justificativa e auditoria; remove de forma transacional biometria, validações e processos preparatórios e limpa os arquivos privados que ficarem órfãos. Depois da primeira emissão, a exclusão é bloqueada e o fluxo correto passa a ser correção cadastral + invalidação/substituição da CIF, preservando o histórico.


## Versão 1.1.1 - acabamento da carteira e assinaturas transparentes

A 1.1.1 parte da versão 1.1.0 e mantém as regras de negócio, banco, numeração e sincronização já consolidadas. A atualização é concentrada na apresentação/produção da CIF e na coleta de imagens biométricas.

Principais ajustes: arte CR80 institucional em PNG para frente e verso; reposicionamento dos dados, foto, QR e assinaturas sobre a arte oficial; assinatura do agente e do signatário sem caixa branca visual; botão de pré-visualização da carteira sem abrir tentativa de impressão; retirada da exigência operacional de 500 DPI; qualidade validada por dimensões reais em pixels; e otimização automática de imagens excessivamente grandes quando o editor de imagens do WordPress estiver disponível.

## Escopo funcional consolidado

O plugin interno contempla:

- autenticação própria do Sistema CIF, independente de `wp_users`, com sessões e credenciais no banco `smtt_cif`;
- autorização própria do Sistema CIF com `ADMIN_CIF`, `EDITOR_CIF` e `CONSULTA_CIF`;
- cadastro, edição, inativação, reativação e exclusão controlada de agentes;
- histórico biográfico e funcional;
- fotografia e assinatura armazenadas em storage privado criptografado;
- processos administrativos de emissão;
- protocolo CIF automático no padrão `CIF-AAAA-000001`;
- protocolo externo opcional e separado;
- validação dos dados pelo titular;
- documentos vinculados ao processo;
- regras de perda, furto, roubo, dano e segunda via;
- numeração da carteira no padrão `AJU##SEBR@@@@`;
- ciclo `##` individual por agente, reservado automaticamente a cada novo processo;
- sequencial `@@@@` global e contínuo para todas as CIFs;
- geração do token QR aleatório de 256 bits;
- arte CR80 de 85,60 mm x 53,98 mm, frente e verso;
- QR Code local, sem API de terceiros;
- impressão e registro de tentativas;
- entrega e ativação da CIF;
- cancelamento administrativo de emissão pendente;
- ocorrências, recuperação, recolhimento e inutilização integrados ao histórico da própria emissão;
- relatórios e exportação CSV;
- auditoria com encadeamento SHA-256;
- diagnóstico técnico do sistema;
- outbox e worker de sincronização assinada com Ed25519.

## Numerações automáticas

### Processo

O protocolo do processo é gerado automaticamente:

```text
CIF-2026-000001
CIF-2026-000002
```

O operador não digita esse número e não pode alterá-lo depois da criação.

### Carteira

A emissão utiliza:

```text
AJU##SEBR@@@@
```

Exemplo:

```text
AJU01SEBR0184
AJU02SEBR0185
```

O trecho `##` é o **ciclo individual do agente** e o trecho `@@@@` é o **sequencial global do sistema**.

Exemplo: se Anderson já teve um primeiro processo/CIF e abre um novo processo quando o sequencial global está em 0004, a nova emissão será `AJU02SEBR0004`.

## Ciclo de emissão

O ciclo é reservado automaticamente na criação do processo:

- primeiro processo do agente → ciclo `01`;
- segundo processo do mesmo agente → ciclo `02`;
- terceiro processo → ciclo `03`;
- ocorrência, recuperação, recolhimento ou inutilização → **não altera o ciclo**.

O operador não digita o ciclo e não existe mais abertura manual de ciclo global.

## Banco de dados

O banco oficial continua independente do banco WordPress, com nome padrão:

```text
smtt_cif
```

A instalação evolui por migrations versionadas. A 1.5.0 acrescenta a migration `20260924_027_agent_cycles`, que introduz o controle de ciclo individual por agente sem renumerar o sequencial global existente.

## Segurança

Princípios preservados:

- o portal público nunca acessa diretamente o banco interno;
- CPF não é usado como consulta pública;
- QR token não é derivado de CPF, matrícula ou código CIF;
- arquivos sensíveis ficam fora da Media Library;
- permissões são verificadas no backend;
- emissões históricas não são apagadas;
- ações críticas geram auditoria;
- sincronização usa Ed25519 com chave privada somente na intranet.

## Sincronização pública

A infraestrutura interna está pronta, porém a sincronização deve permanecer **desabilitada** até que o plugin público `SMTT CIF - Validação` esteja instalado, configurado e homologado.

Depois disso, a execução recomendada em produção será por cron do sistema operacional utilizando WP-CLI:

```bash
wp smtt-cif sync run --limit=50
```

## Testes da versão estável

A suíte local cobre atualmente 42 verificações, incluindo:

- protocolo automático;
- código CIF;
- token de 256 bits;
- CPF;
- máquinas de estado;
- perfis administrativos;
- auditoria criptográfica;
- template CR80;
- catálogo de permissões;
- consistência entre Router e controllers;
- autenticação própria do CIF;
- relatórios navegáveis;
- ciclo individual por agente com sequencial global;
- integração de ocorrências/recolhimentos ao histórico da emissão.

Execute:

```bash
php tests/run.php
```

## Homologação

Consulte:

```text
docs/HOMOLOGACAO-1.0.md
```

Também acompanha a entrega externa o **Manual do Sistema CIF - Gestão 1.0.0**, com passo a passo de instalação, configuração, operação e testes.
