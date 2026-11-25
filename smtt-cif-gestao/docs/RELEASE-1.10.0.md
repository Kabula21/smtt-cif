# SMTT CIF - Gestão 1.10.0

## Sincronização automática

Depois que a URL do portal, a chave Ed25519 e a opção de sincronização estiverem configuradas, o envio ao portal público não depende mais de ação diária do Administrador CIF.

- o fluxo operacional grava somente eventos na outbox local;
- nenhum HTTP para o portal é executado dentro do clique de cadastro, emissão, ativação, ocorrência ou recolhimento;
- um worker automático processa até 25 eventos por execução, aproximadamente a cada minuto;
- o heartbeat é executado pelo worker quando o intervalo configurado vence;
- retries continuam respeitando a política transacional da outbox;
- worker e reconciliação usam um lock MySQL compartilhado para impedir execução concorrente;
- erros automáticos são registrados no estado técnico e no log do WordPress sem interromper o Sistema CIF.

## Reconciliação fatiada

A reconciliação automática não percorre toda a base em cada execução. A cada 15 minutos são verificadas até 200 emissões, em ordem de UUID, com cursor persistido em `sync_state`. Ao chegar ao fim, o cursor reinicia para que um novo ciclo completo seja iniciado nas execuções seguintes.

Divergências `MISSING`, `VERSION_MISMATCH` e `HASH_MISMATCH` continuam sendo reenfileiradas automaticamente. `REMOTE_AHEAD` continua sendo tratado como condição de atenção e nunca altera a base oficial interna.

A reconstrução completa de projeções permanece uma ferramenta manual e excepcional.

## Agendamento

A versão registra dois hooks WP-Cron:

- `smtt_cif_sync_queue_tick` — a cada 60 segundos;
- `smtt_cif_sync_reconcile_tick` — a cada 15 minutos.

Em produção, quando for necessária cadência rigorosa mesmo em períodos sem acesso à intranet, recomenda-se acionar `wp-cron.php` por cron real do servidor. Isso não altera o fluxo funcional do plugin.

## Banco

Migration adicionada:

`20260924_030_automatic_sync_scheduler`

Ela acrescenta ao `sync_state` os marcadores da automação e o cursor da reconciliação automática, sem alterar emissões, códigos CIF, processos, QR Codes ou histórico existente.
