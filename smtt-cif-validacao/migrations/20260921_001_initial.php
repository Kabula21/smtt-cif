<?php

declare(strict_types=1);

return [
    'version' => '20260921_001_initial',
    'description' => 'Estrutura inicial do portal público CIF: projeções, chaves, idempotência, replay, logs, rate limit e configurações.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS public_issuances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    emission_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash BINARY(32) NOT NULL,
    code CHAR(13) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(200) NOT NULL,
    registration VARCHAR(80) NOT NULL,
    issued_at DATETIME NOT NULL,
    public_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    record_version BIGINT UNSIGNED NOT NULL,
    projection_hash BINARY(32) NOT NULL,
    source_updated_at DATETIME NOT NULL,
    first_synced_at DATETIME(6) NOT NULL,
    last_synced_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_public_issuances_emission (emission_uuid),
    UNIQUE KEY uq_public_issuances_token_hash (token_hash),
    UNIQUE KEY uq_public_issuances_code (code),
    KEY idx_public_issuances_status (public_status),
    KEY idx_public_issuances_last_sync (last_synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_events_received (
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_version SMALLINT UNSIGNED NOT NULL,
    body_hash BINARY(32) NOT NULL,
    result VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    received_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_id),
    KEY idx_sync_events_received_at (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_replay_guard (
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_timestamp DATETIME NOT NULL,
    received_at DATETIME(6) NOT NULL,
    endpoint VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (request_id),
    KEY idx_sync_replay_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_public_keys (
    key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    algorithm VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    public_key BINARY(32) NOT NULL,
    fingerprint BINARY(32) NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    valid_from DATETIME(6) NOT NULL,
    valid_until DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (key_id),
    UNIQUE KEY uq_sync_public_keys_fingerprint (fingerprint),
    KEY idx_sync_public_keys_status (status, valid_from, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at DATETIME(6) NOT NULL,
    endpoint VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    result VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    http_status SMALLINT UNSIGNED NOT NULL,
    summary VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_sync_log_time (occurred_at),
    KEY idx_sync_log_request (request_id),
    KEY idx_sync_log_result_time (result, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS plugin_settings (
    setting_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    setting_value JSON NOT NULL,
    description VARCHAR(500) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO plugin_settings(setting_key,setting_value,description,updated_at) VALUES
('sync.environment', '"homologation"', 'Ambiente aceito nas requisições assinadas.', UTC_TIMESTAMP(6)),
('security.timestamp_window_seconds', '300', 'Janela máxima de diferença do timestamp assinado.', UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS validation_rate_limits (
    client_hash BINARY(32) NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL,
    blocked_until DATETIME NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (client_hash),
    KEY idx_validation_rate_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
