<?php

declare(strict_types=1);

return [
    'version' => '20260919_005_audit_sync',
    'description' => 'Auditoria append-only, transactional outbox, tentativas, reconciliação e configurações não secretas.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS auditoria (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ocorrido_em DATETIME(6) NOT NULL,
    usuario_cif_id BIGINT UNSIGNED NULL,
    actor_wp_user_id BIGINT UNSIGNED NULL,
    actor_login VARCHAR(100) NULL,
    actor_nome VARCHAR(200) NULL,
    operacao VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entidade_tipo VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entidade_id BIGINT UNSIGNED NULL,
    entidade_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    valor_anterior JSON NULL,
    valor_posterior JSON NULL,
    justificativa TEXT NULL,
    ip_origem VARBINARY(16) NULL,
    user_agent VARCHAR(500) NULL,
    origem VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    previous_hash BINARY(32) NULL,
    record_hash BINARY(32) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auditoria_uuid (uuid),
    KEY idx_auditoria_data (ocorrido_em),
    KEY idx_auditoria_usuario_data (usuario_cif_id, ocorrido_em),
    KEY idx_auditoria_entidade (entidade_tipo, entidade_id, ocorrido_em),
    KEY idx_auditoria_operacao_data (operacao, ocorrido_em),
    KEY idx_auditoria_request (request_id),
    CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_cif_id) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_chaves_assinatura (
    key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    algoritmo VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    private_key_reference VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    public_key_fingerprint BINARY(32) NOT NULL,
    vigencia_inicio DATETIME(6) NOT NULL,
    vigencia_fim DATETIME(6) NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (key_id),
    KEY idx_sync_chaves_status_vigencia (status, vigencia_inicio, vigencia_fim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aggregate_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aggregate_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aggregate_version BIGINT UNSIGNED NOT NULL,
    payload_version SMALLINT UNSIGNED NOT NULL,
    payload JSON NOT NULL,
    payload_hash BINARY(32) NOT NULL,
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    criado_em DATETIME(6) NOT NULL,
    disponivel_em DATETIME(6) NOT NULL,
    proxima_tentativa_em DATETIME(6) NULL,
    locked_em DATETIME(6) NULL,
    locked_by VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    enviado_em DATETIME(6) NULL,
    ultimo_erro_codigo VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ultimo_erro_resumo VARCHAR(1000) NULL,
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_outbox_event (event_uuid),
    KEY idx_sync_outbox_worker (status, proxima_tentativa_em, disponivel_em),
    KEY idx_sync_outbox_aggregate (aggregate_type, aggregate_uuid, aggregate_version),
    KEY idx_sync_outbox_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outbox_id BIGINT UNSIGNED NOT NULL,
    numero_tentativa SMALLINT UNSIGNED NOT NULL,
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_time DATETIME(6) NOT NULL,
    key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    algoritmo_assinatura VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    resultado VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    duracao_ms INT UNSIGNED NULL,
    response_hash BINARY(32) NULL,
    erro_tipo VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    erro_resumo VARCHAR(1000) NULL,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_attempts_outbox_numero (outbox_id, numero_tentativa),
    UNIQUE KEY uq_sync_attempts_request (request_id),
    CONSTRAINT fk_sync_attempts_outbox FOREIGN KEY (outbox_id) REFERENCES sync_outbox (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_projection_state (
    aggregate_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aggregate_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    desired_version BIGINT UNSIGNED NOT NULL,
    synced_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    desired_hash BINARY(32) NOT NULL,
    synced_hash BINARY(32) NULL,
    last_enqueued_at DATETIME(6) NOT NULL,
    last_synced_at DATETIME(6) NULL,
    last_error_at DATETIME(6) NULL,
    PRIMARY KEY (aggregate_type, aggregate_uuid),
    KEY idx_sync_projection_versions (desired_version, synced_version),
    KEY idx_sync_projection_sync_time (last_synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_reconciliation_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    iniciado_em DATETIME(6) NOT NULL,
    finalizado_em DATETIME(6) NULL,
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registros_verificados INT UNSIGNED NOT NULL DEFAULT 0,
    divergencias INT UNSIGNED NOT NULL DEFAULT 0,
    reenfileirados INT UNSIGNED NOT NULL DEFAULT 0,
    erros INT UNSIGNED NOT NULL DEFAULT 0,
    resumo JSON NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_reconciliation_uuid (uuid),
    KEY idx_sync_reconciliation_status_data (status, iniciado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_state (
    id TINYINT UNSIGNED NOT NULL,
    ultima_tentativa_em DATETIME(6) NULL,
    ultimo_sucesso_em DATETIME(6) NULL,
    ultimo_heartbeat_envio_em DATETIME(6) NULL,
    ultimo_heartbeat_ok_em DATETIME(6) NULL,
    ultima_reconciliacao_em DATETIME(6) NULL,
    remote_plugin_version VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
    remote_schema_version VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    falhas_consecutivas INT UNSIGNED NOT NULL DEFAULT 0,
    atualizado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT IGNORE INTO sync_state (id, falhas_consecutivas, atualizado_em)
VALUES (1, 0, UTC_TIMESTAMP(6))
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    valor JSON NOT NULL,
    descricao VARCHAR(500) NULL,
    atualizado_por BIGINT UNSIGNED NULL,
    atualizado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (chave),
    CONSTRAINT fk_configuracoes_usuario FOREIGN KEY (atualizado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
