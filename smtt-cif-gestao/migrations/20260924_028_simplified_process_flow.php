<?php

declare(strict_types=1);

return [
    'version' => '20260924_028_simplified_process_flow',
    'description' => 'Remove a carência automática de 45 dias dos processos existentes; novos processos entram diretamente em tramitação.',
    'up' => [
        <<<'SQL'
UPDATE processos_emissao
SET elegivel_emissao_em = NULL,
    atualizado_em = UTC_TIMESTAMP(6),
    lock_version = lock_version + 1
WHERE elegivel_emissao_em IS NOT NULL
SQL,
    ],
];
