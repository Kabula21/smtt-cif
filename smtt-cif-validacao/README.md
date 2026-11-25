# SMTT CIF - Validação 0.4.0

Plugin WordPress público para validar a Carteira de Identificação Funcional dos Agentes de Trânsito da SMTT Aracaju.

## Escopo da versão

A versão 0.4.0 mantém a integração funcional com o SMTT CIF - Gestão e simplifica a consulta pública: o QR exclusivo da emissão passa a abrir diretamente a situação da CIF, sem segunda digitação de código.

- banco lógico independente `smtt_cif_public`;
- migrations próprias;
- receptor assinado Ed25519;
- anti-replay por `request_id`;
- idempotência por `event_id`;
- versionamento monotônico das projeções;
- exibição da data de admissão recebida na projeção pública;
- heartbeat;
- reconciliação;
- rota pública `/validar/{TOKEN}`;
- validação direta por token QR criptograficamente aleatório de 256 bits;
- o código CIF deixa de ser fator de autenticação da consulta pública;
- rate limiting por origem;
- página pública `noindex` e `no-store`;
- painel técnico WordPress restrito a `manage_options`;
- nenhuma edição manual das projeções recebidas;
- seletor explícito LOCAL/HOMOLOGAÇÃO ↔ PRODUÇÃO;
- mapeamento automático LOCAL → `homologation` e PRODUÇÃO → `production`;
- bloqueio de Produção sem HTTPS;
- alerta visual nas consultas públicas de homologação;
- proteção contra Key IDs `hml`/`prod` claramente incompatíveis com o ambiente.

## Requisitos

- WordPress com PHP 8.1 ou superior;
- extensões PHP `mysqli` e `sodium`;
- MySQL/MariaDB acessível pelo WordPress;
- permalinks/rewrite do WordPress funcionando para `/validar/{TOKEN}` e `/wp-json/...`;
- HTTPS em produção.

## Banco público

Por padrão o banco chama-se `smtt_cif_public`. O plugin pode criar esse banco automaticamente se a credencial possuir permissão `CREATE DATABASE`.

Se necessário, configure no `wp-config.php`:

```php
define('SMTT_CIF_PUBLIC_DB_NAME', 'smtt_cif_public');
define('SMTT_CIF_PUBLIC_DB_HOST', DB_HOST);
define('SMTT_CIF_PUBLIC_DB_USER', DB_USER);
define('SMTT_CIF_PUBLIC_DB_PASSWORD', DB_PASSWORD);
```

`SMTT_CIF_PUBLIC_DB_HOST`, usuário e senha são opcionais: na ausência dessas constantes, o plugin reutiliza as credenciais de conexão do WordPress somente para acessar o servidor MySQL/MariaDB. O banco lógico continua separado.

## Instalação inicial

1. Instale e ative o ZIP no WordPress público.
2. Abra **CIF Validação** no wp-admin.
3. Mantenha **LOCAL / HOMOLOGAÇÃO** durante os testes locais. O plugin aceitará somente `homologation`.
4. No Gestão, gere uma chave Ed25519 e copie o `key_id` e a chave pública Base64.
5. No Validação, adicione essa chave na seção **Chaves públicas Ed25519 aceitas**.
6. Somente depois, no Gestão, ative a mesma chave e configure a URL base do portal público.
7. Execute heartbeat no Gestão.
8. Faça uma ativação/entrega CIF ou reenfileire uma projeção para testar a sincronização.
9. Somente no portal externo definitivo, com HTTPS configurado, altere a chave de ambiente do plugin para **PRODUÇÃO**.

## Endpoints

- `POST /wp-json/smtt-cif/v1/sync/events`
- `POST /wp-json/smtt-cif/v1/sync/heartbeat`
- `POST /wp-json/smtt-cif/v1/sync/reconcile`

Todos exigem assinatura Ed25519 do Gestão.

## Consulta pública

O QR deve apontar para:

```text
https://portal.exemplo/validar/{TOKEN}
```

O token do QR é exclusivo por emissão e possui 256 bits de entropia. Ao abrir uma URL válida, o portal calcula o SHA-256 do token recebido e localiza diretamente a projeção correspondente. Não existe formulário público de pesquisa por código.

Dados exibidos quando o token corresponde a uma emissão sincronizada:

- nome;
- matrícula;
- código CIF;
- data de admissão;
- data de emissão;
- situação pública;
- última sincronização.

Não são disponibilizadas buscas por nome, CPF, matrícula ou código CIF.

## Privacidade

O banco público não contém CPF, nascimento, naturalidade, tipo sanguíneo, documentos, biometria, processos ou auditoria interna. O token QR não é persistido em plaintext: somente seu SHA-256 é armazenado.

O plugin não inclui o token completo nos logs técnicos próprios. Como o token faz parte da URL do QR, em produção o servidor HTTP/reverse proxy também deve ser configurado para não registrar integralmente caminhos `/validar/{TOKEN}` em access logs. O caminho não é tratado como segredo por obscuridade: a segurança depende da imprevisibilidade do token, de `noindex`, ausência de busca pública, respostas genéricas e rate limiting de tentativas inválidas.

## Contrato técnico

Consulte `docs/INTEGRATION-CONTRACT.md`.
