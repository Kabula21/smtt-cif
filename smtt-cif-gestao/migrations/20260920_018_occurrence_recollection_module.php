<?php

declare(strict_types=1);

return [
    'version' => '20260920_018_occurrence_recollection_module',
    'description' => 'Ciclo operacional de ocorrências, recuperação, recolhimento e inutilização pós-emissão.',
    'up' => [
        <<<'SQL'
ALTER TABLE ocorrencias_cif
    ADD COLUMN status VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ABERTA' AFTER tipo_codigo,
    ADD COLUMN motivo_encerramento VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER recuperada_em,
    ADD COLUMN encerrada_em DATETIME(6) NULL AFTER motivo_encerramento,
    ADD COLUMN atualizado_em DATETIME(6) NULL AFTER registrado_em,
    ADD KEY idx_ocorrencias_status_data (status, data_comunicacao)
SQL,
        <<<'SQL'
UPDATE ocorrencias_cif
SET status = CASE WHEN recuperada_em IS NULL THEN 'ABERTA' ELSE 'RECUPERADA_REATIVADA' END,
    encerrada_em = CASE WHEN recuperada_em IS NULL THEN NULL ELSE recuperada_em END,
    atualizado_em = COALESCE(recuperada_em, registrado_em)
WHERE atualizado_em IS NULL
SQL,
        <<<'SQL'
ALTER TABLE recolhimentos_cif
    ADD COLUMN referencia VARCHAR(200) NULL AFTER motivo_nao_recolhimento,
    ADD COLUMN observacoes TEXT NULL AFTER referencia,
    ADD COLUMN recolhida_por BIGINT UNSIGNED NULL AFTER registrado_por,
    ADD COLUMN inutilizada_por BIGINT UNSIGNED NULL AFTER recolhida_por,
    ADD COLUMN nao_recolhimento_registrado_por BIGINT UNSIGNED NULL AFTER inutilizada_por,
    ADD KEY idx_recolhimentos_emissao_criado (emissao_id, criado_em),
    ADD CONSTRAINT fk_recolhimentos_recolhida_por FOREIGN KEY (recolhida_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_recolhimentos_inutilizada_por FOREIGN KEY (inutilizada_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_recolhimentos_nao_recolhimento_por FOREIGN KEY (nao_recolhimento_registrado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        <<<'SQL'
UPDATE recolhimentos_cif
SET recolhida_por = CASE WHEN recolhida_em IS NOT NULL THEN registrado_por ELSE recolhida_por END,
    inutilizacao_permitida_em = CASE
        WHEN motivo_codigo IN ('SUBSTITUICAO_CIF','SUBSTITUICAO_NOVO_MODELO','RECUPERACAO_APOS_SUBSTITUICAO')
             AND recolhida_em IS NOT NULL
        THEN COALESCE(inutilizacao_permitida_em, recolhida_em)
        ELSE inutilizacao_permitida_em
    END
WHERE recolhida_em IS NOT NULL
SQL,
    ],
];
