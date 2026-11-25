<?php

declare(strict_types=1);

return [
    'version' => '20260919_012_biometry_capture_metadata',
    'description' => 'Metadados de validação da coleta biométrica e controle de invalidação sem exclusão histórica.',
    'up' => [
        <<<'SQL'
ALTER TABLE biometrias
    ADD COLUMN dpi_origem VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'OPERADOR_DECLARADO' AFTER dpi,
    ADD COLUMN validacao JSON NULL AFTER conforme_padrao
SQL,
        <<<'SQL'
ALTER TABLE coletas_biometricas
    ADD COLUMN invalidada_por BIGINT UNSIGNED NULL AFTER status,
    ADD COLUMN invalidada_em DATETIME(6) NULL AFTER invalidada_por,
    ADD COLUMN motivo_invalidacao VARCHAR(500) NULL AFTER invalidada_em,
    ADD KEY idx_coletas_agente_status_data (agente_id, status, coletada_em),
    ADD CONSTRAINT fk_coletas_invalidada_por FOREIGN KEY (invalidada_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
    ],
];
