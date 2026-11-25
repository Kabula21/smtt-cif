<?php

declare(strict_types=1);

return [
    'version' => '20260921_024_automatic_process_protocol',
    'description' => 'Protocolo CIF automático, referência externa separada e controle transacional da sequência de processos.',
    'up' => [
        <<<'SQL'
ALTER TABLE processos_emissao
    ADD COLUMN protocolo_externo VARCHAR(150) NULL AFTER numero_protocolo
SQL,
        <<<'SQL'
UPDATE processos_emissao
SET protocolo_externo = numero_protocolo
WHERE numero_protocolo IS NOT NULL AND TRIM(numero_protocolo) <> ''
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS controle_numeracao_processos (
    id TINYINT UNSIGNED NOT NULL,
    ultimo_sequencial INT UNSIGNED NOT NULL DEFAULT 0,
    atualizado_em DATETIME(6) NOT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO controle_numeracao_processos (id, ultimo_sequencial, atualizado_em, lock_version)
SELECT 1, COALESCE(MAX(id), 0), UTC_TIMESTAMP(6), 0
FROM processos_emissao
ON DUPLICATE KEY UPDATE ultimo_sequencial = GREATEST(ultimo_sequencial, VALUES(ultimo_sequencial)), atualizado_em = VALUES(atualizado_em)
SQL,
        <<<'SQL'
UPDATE processos_emissao
SET numero_protocolo = CONCAT(
    'CIF-',
    YEAR(DATE_SUB(criado_em, INTERVAL 3 HOUR)),
    '-',
    CASE WHEN id < 1000000 THEN LPAD(id, 6, '0') ELSE CAST(id AS CHAR) END
)
SQL,
        <<<'SQL'
ALTER TABLE processos_emissao
    DROP INDEX idx_processos_protocolo,
    MODIFY numero_protocolo VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD UNIQUE KEY uq_processos_numero_protocolo (numero_protocolo),
    ADD KEY idx_processos_protocolo_externo (protocolo_externo)
SQL,
    ],
];
