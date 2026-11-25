<?php

declare(strict_types=1);

return [
    'version' => '20260919_007_agents_current_identity',
    'description' => 'Índice de identidade corrente do agente para unicidade de CPF sem expor o documento em texto puro.',
    'up' => [
        <<<'SQL'
ALTER TABLE agentes
    ADD COLUMN cpf_lookup_hmac BINARY(32) NULL AFTER matricula,
    ADD UNIQUE KEY uq_agentes_cpf_lookup_hmac (cpf_lookup_hmac)
SQL,
    ],
];
