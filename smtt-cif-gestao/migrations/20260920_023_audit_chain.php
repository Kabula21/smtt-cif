<?php

declare(strict_types=1);

return [
    'version' => '20260920_023_audit_chain',
    'description' => 'Ativa âncora e encadeamento SHA-256 para novos registros de auditoria, preservando o legado anterior sem reescrita.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS auditoria_chain_anchor (
    id TINYINT UNSIGNED NOT NULL,
    legacy_last_id BIGINT UNSIGNED NOT NULL,
    legacy_count BIGINT UNSIGNED NOT NULL,
    genesis_hash BINARY(32) NOT NULL,
    last_hash BINARY(32) NOT NULL,
    last_audit_id BIGINT UNSIGNED NOT NULL,
    criado_em DATETIME(6) NOT NULL,
    atualizado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_auditoria_chain_last (last_audit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT IGNORE INTO auditoria_chain_anchor (
    id, legacy_last_id, legacy_count, genesis_hash, last_hash, last_audit_id, criado_em, atualizado_em
)
SELECT 1, x.max_id, x.legacy_count, x.genesis_hash, x.genesis_hash, x.max_id, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
FROM (
    SELECT COALESCE(MAX(id),0) AS max_id, COUNT(*) AS legacy_count,
           UNHEX(SHA2(CONCAT('SMTT-CIF-AUDIT-V1|CUTOVER|', COUNT(*), '|', COALESCE(MAX(id),0)), 256)) AS genesis_hash
    FROM auditoria
) x
SQL,
        <<<'SQL'
ALTER TABLE auditoria
    ADD KEY idx_auditoria_entidade_uuid_data (entidade_uuid, ocorrido_em)
SQL,
    ],
];
