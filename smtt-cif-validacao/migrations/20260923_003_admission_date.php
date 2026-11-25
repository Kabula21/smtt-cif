<?php

declare(strict_types=1);

return [
    'version' => '20260923_003_admission_date',
    'description' => 'Adiciona a data de admissão à projeção pública mínima da CIF.',
    'up' => [
        <<<'SQL'
ALTER TABLE public_issuances
    ADD COLUMN admission_date DATE NULL AFTER registration
SQL,
    ],
];
