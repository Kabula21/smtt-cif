# Notas do schema 0.1.0

## Grupos de tabelas

### Autorização interna

`usuarios_cif`, `perfis_acesso`, `permissoes`, `usuario_perfis`, `perfil_permissoes`, `usuario_permissoes`.

As roles/capabilities do WordPress não fazem parte do modelo de autorização CIF.

### Agentes e biometria

`agentes`, `agentes_dados_biograficos`, `agentes_situacoes_funcionais`, `arquivos_privados`, `coletas_biometricas`, `biometrias`, `signatarios_institucionais`.

### Processos e numeração

`processos_emissao`, `processos_documentos`, `processos_eventos`, `validacoes_dados_biograficos`, `controle_numeracao_processos`, `ciclos_emissao`, `controle_numeracao_cif`.

### Emissões e ciclo de vida

`emissoes_cif`, `processos_emissoes_origem`, `emissoes_cif_snapshot`, `emissoes_cif_biometrias`, `emissoes_cif_estado`, `agentes_emissoes_ativas`, `eventos_cif`, `ocorrencias_cif`, `ocorrencias_documentos`, `recolhimentos_cif`, `artefatos_emissao`, `impressoes_cif`.

### Auditoria e sincronização

`auditoria`, `sync_chaves_assinatura`, `sync_outbox`, `sync_attempts`, `sync_projection_state`, `sync_reconciliation_runs`, `sync_state`, `configuracoes`.

## Não exclusão automática

As foreign keys de registros oficiais utilizam `RESTRICT`. O domínio trabalhará com encerramento/inativação/eventos, não exclusão física de emissões ou histórico.

## Estados

As colunas de estados permanecem `VARCHAR` neste primeiro schema. As transições não serão autorizadas por simples UPDATE: serão aplicadas pelos Services e pela máquina de estados. Isso permite evoluir o catálogo sem depender de `ENUM` e mantém compatibilidade entre MySQL e MariaDB.

## Auditoria

`auditoria` já possui `previous_hash` e `record_hash` para permitir encadeamento de integridade. O cálculo será implementado no módulo de auditoria; os campos ainda não são preenchidos por este incremento.

## Biometria (0.4.0)

`coletas_biometricas` representa uma sessão versionada de fotografia + assinatura. Uma nova coleta não sobrescreve a anterior.

`biometrias` registra os metadados técnicos de cada item, incluindo DPI, origem da medição e JSON de validação. `arquivos_privados` referencia os bytes criptografados no storage privado.

O campo legado `reutilizavel_ate` de `coletas_biometricas` permanece `NULL` nesta fase. A decisão de reutilizar biometria será feita pelo módulo de emissão, porque a regra normativa relaciona a dispensa de nova coleta ao intervalo desde a última emissão, e não simplesmente à data em que o arquivo biométrico foi capturado.
