# Matriz de permissões — SMTT CIF Gestão 0.15

## Regra de autoridade

- **Administrador WordPress com vínculo CIF ativo**: autoridade integral dentro do plugin.
- **ADMIN_CIF**: autoridade administrativa integral do Sistema CIF, respeitando invariantes de integridade e auditoria.
- **EDITOR_CIF**: operação e manutenção expressamente autorizadas; não recebe recursos críticos de configuração, sincronização, remoção, aprovação administrativa ou inutilização.
- **CONSULTA_CIF**: leitura apenas; não edita, não exclui, não tramita e não executa operações de negócio.

## Exclusões e remoções

- **Agentes**: exclusão física somente se o cadastro ainda não tiver vínculos históricos. Quando houver histórico, o administrador pode arquivar, mas não destruir registros vinculados.
- **Usuários CIF**: somente administrador pode remover o acesso CIF de outro usuário. A conta WordPress não é apagada; o vínculo e o histórico de auditoria são preservados.
- **Emissões / ocorrências / recolhimentos / auditoria**: não são apagados como mecanismo operacional; utilizam status, cancelamento, encerramento, recolhimento ou inutilização conforme o domínio.

## Áreas administrativas críticas

As áreas abaixo exigem Administrador CIF ou Administrador WordPress com vínculo CIF ativo, inclusive no backend:

- Sincronização;
- Usuários e acessos;
- Configurações e preparação da emissão;
- Numeração/ciclos;
- Signatário institucional;
- URL de validação pública.
