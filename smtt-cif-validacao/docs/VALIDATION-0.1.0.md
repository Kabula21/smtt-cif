# Validação técnica — SMTT CIF Validação 0.1.0

Data: 2026-09-21

## Verificações executadas

- PHP lint em todos os arquivos `.php` do plugin.
- Testes automatizados em `tests/run.php`.
- Teste de Base64URL para token de 256 bits.
- Teste da string canônica do protocolo v1.
- Teste de assinatura/verificação Ed25519.
- Teste do schema estrito de `CIF_PROJECTION_BATCH`.
- Teste de rejeição de campo inesperado.
- Teste de rejeição de token fora do formato.
- Teste do payload de heartbeat.
- Teste do payload de reconciliação.
- Verificação estática das tabelas obrigatórias da migration inicial.
- Verificação de ausência de `DROP`/`TRUNCATE` na migration inicial.
- Busca por campos sensíveis proibidos no schema/código de persistência pública.
- Busca por uso de `wp_posts`, `wp_postmeta` e `wp_options` como armazenamento da projeção.
- Busca por logging explícito do token.
- Verificação de integridade do ZIP após empacotamento.

## JavaScript

A versão 0.1.0 não contém arquivos JavaScript. Não há fonte JS a submeter a `node --check`.

## Limites desta validação

Não foi executado navegador WordPress real nesta sessão. Também não foi executada a migration contra um servidor MySQL/MariaDB de produção/homologação real. A migration foi verificada estruturalmente e pelo carregamento PHP do arquivo de definição; sua execução efetiva ocorre no hook de ativação/admin do plugin.

Esses limites são deliberadamente registrados para não confundir teste estático/automatizado com homologação visual e operacional em WordPress real.
