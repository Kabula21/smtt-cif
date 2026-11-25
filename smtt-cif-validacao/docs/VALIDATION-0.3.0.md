# Validação técnica — SMTT CIF Validação 0.3.0

Data: 2026-09-22

## Verificações previstas para o release

- PHP lint em todos os arquivos `.php`;
- testes automatizados em `tests/run.php`;
- Base64URL de token de 256 bits;
- canonicalização e assinatura Ed25519;
- schemas estritos de eventos, heartbeat e reconciliação;
- migrations existentes sem alteração;
- marca SMTT empacotada localmente no plugin;
- página pública sem dependência de JavaScript;
- integridade do ZIP e SHA-256.

## Escopo visual

A versão 0.3.0 simplifica a página pública para um layout institucional discreto: marca SMTT, título/orientação, card central de consulta e estados de resultado. Não há alteração de contrato de integração nem de persistência.

## Limite da validação automatizada

A renderização final deve ser homologada visualmente no WordPress local pelo operador. Testes automatizados e lint não substituem a revisão em navegador real.
