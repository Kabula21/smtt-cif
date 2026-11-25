<?php

declare(strict_types=1);

return [
    'version' => '20260924_027_agent_cycles',
    'description' => 'Ciclo de emissão individual por agente/processo, preservando o sequencial global contínuo da CIF.',
    'up' => [
        <<<'SQL'
ALTER TABLE processos_emissao
    ADD COLUMN ciclo_numero TINYINT UNSIGNED NULL AFTER agente_id
SQL,
        <<<'SQL'
UPDATE processos_emissao p
JOIN (
    SELECT ranked_inner.id, ranked_inner.ciclo
    FROM (
        SELECT p1.id,
               (
                   SELECT COUNT(*)
                   FROM processos_emissao p2
                   WHERE p2.agente_id = p1.agente_id
                     AND (
                         p2.criado_em < p1.criado_em
                         OR (p2.criado_em = p1.criado_em AND p2.id <= p1.id)
                     )
               ) AS ciclo
        FROM processos_emissao p1
    ) ranked_inner
) ranked ON ranked.id = p.id
SET p.ciclo_numero = ranked.ciclo
WHERE p.ciclo_numero IS NULL
SQL,
        <<<'SQL'
ALTER TABLE processos_emissao
    MODIFY ciclo_numero TINYINT UNSIGNED NOT NULL,
    ADD KEY idx_processos_agente_ciclo (agente_id, ciclo_numero)
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS controle_ciclo_agente (
    agente_id BIGINT UNSIGNED NOT NULL,
    ultimo_ciclo TINYINT UNSIGNED NOT NULL DEFAULT 0,
    atualizado_em DATETIME(6) NOT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (agente_id),
    CONSTRAINT fk_controle_ciclo_agente FOREIGN KEY (agente_id) REFERENCES agentes (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO controle_ciclo_agente (agente_id, ultimo_ciclo, atualizado_em, lock_version)
SELECT agente_id, MAX(ciclo_numero), UTC_TIMESTAMP(6), 0
FROM processos_emissao
GROUP BY agente_id
ON DUPLICATE KEY UPDATE
    ultimo_ciclo = GREATEST(ultimo_ciclo, VALUES(ultimo_ciclo)),
    atualizado_em = VALUES(atualizado_em)
SQL,
        <<<'SQL'
ALTER TABLE emissoes_cif
    ADD COLUMN ciclo_numero_agente TINYINT UNSIGNED NULL AFTER ciclo_id,
    ADD KEY idx_emissoes_agente_ciclo (agente_id, ciclo_numero_agente)
SQL,
        <<<'SQL'
UPDATE emissoes_cif e
SET e.ciclo_numero_agente = CAST(SUBSTRING(e.codigo_validacao, 4, 2) AS UNSIGNED)
WHERE e.ciclo_numero_agente IS NULL
SQL,
        <<<'SQL'
ALTER TABLE emissoes_cif
    MODIFY ciclo_id BIGINT UNSIGNED NULL
SQL,
    ],
];
