<?php

declare(strict_types=1);

return [
    'version' => '20260924_030_automatic_sync_scheduler',
    'description' => 'Estado operacional do worker automático e cursor fatiado da reconciliação pública.',
    'up' => [
        <<<'SQL'
ALTER TABLE sync_state
    ADD COLUMN ultimo_worker_automatico_em DATETIME(6) NULL AFTER ultima_reconciliacao_em,
    ADD COLUMN ultimo_worker_automatico_ok_em DATETIME(6) NULL AFTER ultimo_worker_automatico_em,
    ADD COLUMN ultima_reconciliacao_automatica_em DATETIME(6) NULL AFTER ultimo_worker_automatico_ok_em,
    ADD COLUMN auto_reconcile_cursor CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER ultima_reconciliacao_automatica_em,
    ADD COLUMN ultimo_erro_automatico VARCHAR(500) NULL AFTER auto_reconcile_cursor
SQL,
    ],
];
