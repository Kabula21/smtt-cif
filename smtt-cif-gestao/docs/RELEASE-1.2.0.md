# SMTT CIF - Gestão 1.2.0

## Autenticação nativa do Sistema CIF

A versão 1.2.0 separa definitivamente os usuários operacionais do Sistema CIF das contas do WordPress. O `wp-admin` permanece reservado à administração técnica da plataforma pela TI; operadores do CIF passam a autenticar em `/cif/login` com credenciais armazenadas exclusivamente no banco próprio `smtt_cif`.

### Identidade e sessão

- usuários CIF próprios com login, e-mail, nome, setor, senha e perfil;
- senhas armazenadas somente por hash forte (`Argon2id` quando disponível, com fallback seguro do PHP);
- sessão própria do CIF, com token aleatório e somente o hash persistido em `sessoes_cif`;
- cookie `HttpOnly`, `SameSite=Lax` e `Secure` quando HTTPS estiver ativo;
- proteção CSRF própria, sem depender da sessão de usuário do WordPress;
- bloqueio temporário após tentativas consecutivas inválidas;
- troca obrigatória da senha inicial e após redefinição administrativa;
- alteração voluntária de senha pelo próprio usuário;
- inativação/redefinição de senha revogam sessões do usuário.

### Perfis

A governança permanece baseada nos perfis internos:

- `ADMIN_CIF` — administração integral do Sistema CIF, inclusive usuários e configurações;
- `EDITOR_CIF` — operações autorizadas pela matriz de permissões, sem administração de segurança/configuração;
- `CONSULTA_CIF` — consultas expressamente autorizadas, sem alteração de dados.

Contas WordPress não concedem autoridade dentro do CIF.

### Administração de usuários

O Administrador CIF pode criar, editar, redefinir senha, inativar, reativar e, quando não houver histórico, excluir definitivamente usuários CIF. Usuários que já possuam autoria ou vínculos históricos não podem ser apagados; devem ser inativados para preservar rastreabilidade.

### Auditoria e relatórios

As ações continuam vinculadas ao `usuario_cif_id`, com snapshot de login/nome no registro de auditoria. Foi acrescentado o relatório administrativo **Atividade dos usuários**, pesquisável por usuário, login, setor, ação e entidade, para responder quem realizou cada operação.

### Migração de instalações existentes

A migration `20260924_025_native_cif_auth` preserva os IDs existentes de `usuarios_cif` e seus vínculos históricos. O primeiro `ADMIN_CIF` legado é convertido em identidade nativa no bootstrap feito por um administrador técnico no `wp-admin`. Os demais usuários legados permanecem visíveis ao Administrador CIF e precisam receber e-mail/setor/senha próprios antes do novo acesso.

Nenhum registro de agente, biometria, processo, emissão, ocorrência, auditoria, artefato, numeração ou sincronização é recriado por esta atualização.
