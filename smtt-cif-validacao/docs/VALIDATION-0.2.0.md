# Validação técnica — SMTT CIF Validação 0.2.0

Data: 2026-09-21

## Verificações previstas para o release

- PHP lint em todos os arquivos `.php`;
- testes automatizados em `tests/run.php`;
- Base64URL de token de 256 bits;
- canonicalização e assinatura Ed25519;
- schemas estritos de eventos, heartbeat e reconciliação;
- identificação de Key IDs `hml` e `prod`;
- migration inicial e migration do modo dual sem `DROP`/`TRUNCATE`;
- busca estática por dados sensíveis proibidos;
- ausência de armazenamento da projeção em `wp_posts`, `wp_postmeta` ou `wp_options`;
- integridade do ZIP e SHA-256.

## JavaScript

A versão 0.2.0 não contém arquivos JavaScript. A chave de ambiente funciona sem dependência de JavaScript.

## Limite da validação automatizada

A homologação funcional será feita pelo operador no WordPress local com os dois plugins reais instalados. O ZIP não é considerado homologado em produção apenas por passar lint e testes automatizados.
