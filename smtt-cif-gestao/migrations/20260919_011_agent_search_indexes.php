<?php

declare(strict_types=1);

return [
    'version' => '20260919_011_agent_search_indexes',
    'description' => 'Índices auxiliares para filtros e ordenações do cadastro de agentes.',
    'up' => [
        <<<'SQL'
ALTER TABLE agentes
    ADD KEY idx_agentes_criado_em (criado_em),
    ADD KEY idx_agentes_atualizado_em (atualizado_em)
SQL,
        <<<'SQL'
ALTER TABLE agentes_dados_biograficos
    ADD KEY idx_biograficos_agente_versao_desc (agente_id, versao),
    ADD KEY idx_biograficos_data_admissao (data_admissao),
    ADD KEY idx_biograficos_fonte (fonte)
SQL,
    ],
];
