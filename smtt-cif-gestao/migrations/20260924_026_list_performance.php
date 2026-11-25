<?php

declare(strict_types=1);

return [
    'version' => '20260924_026_list_performance',
    'description' => 'Índices para paginação, filtros e ordenação das listagens operacionais do Sistema CIF.',
    'up' => [
        <<<'SQL'
ALTER TABLE agentes
    ADD KEY idx_agentes_ativo_situacao (ativo, situacao_funcional_atual)
SQL,
        <<<'SQL'
ALTER TABLE processos_emissao
    ADD KEY idx_processos_criado_id (criado_em, id),
    ADD KEY idx_processos_status_criado (status_codigo, criado_em, id),
    ADD KEY idx_processos_tipo_criado (tipo_codigo, criado_em, id)
SQL,
        <<<'SQL'
ALTER TABLE emissoes_cif
    ADD KEY idx_emissoes_emitida_id (emitida_em, id),
    ADD KEY idx_emissoes_tipo_emitida (tipo_emissao_codigo, emitida_em, id)
SQL,
        <<<'SQL'
ALTER TABLE ocorrencias_cif
    ADD KEY idx_ocorrencias_data_id (data_comunicacao, id),
    ADD KEY idx_ocorrencias_tipo_data (tipo_codigo, data_comunicacao, id)
SQL,
        <<<'SQL'
ALTER TABLE recolhimentos_cif
    ADD KEY idx_recolhimentos_criado_id (criado_em, id),
    ADD KEY idx_recolhimentos_status_criado (status, criado_em, id),
    ADD KEY idx_recolhimentos_motivo_criado (motivo_codigo, criado_em, id)
SQL,
    ],
];
