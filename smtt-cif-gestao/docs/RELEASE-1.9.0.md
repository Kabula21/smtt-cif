# SMTT CIF - Gestão 1.9.0

## Perfis

- `EDITOR_CIF` passa a visualizar o módulo de Relatórios.
- `CONSULTA_CIF` continua com acesso de consulta aos Relatórios.
- **Atividade dos usuários** continua exclusiva do `ADMIN_CIF`.
- A preparação/configuração institucional de emissão fica visível e acessível somente ao Administrador CIF.

## Sincronização

A operação diária passa a usar um único comando, **Sincronizar agora**. O comando:

1. testa o heartbeat/protocolo com o portal público;
2. processa a fila transacional;
3. reconcilia as projeções;
4. reenfileira automaticamente divergências reparáveis;
5. tenta entregar as correções reenfileiradas.

A reconstrução total não é executada em toda sincronização porque seria redundante e onerosa. Ela permanece em **Ferramentas avançadas**, juntamente com os comandos isolados de diagnóstico.
