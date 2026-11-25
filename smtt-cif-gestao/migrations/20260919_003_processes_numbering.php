<?php

declare(strict_types=1);

return [
    'version' => '20260919_003_processes_numbering',
    'description' => 'Processos administrativos, validação biográfica, documentos e controle transacional de ciclos/numeracão.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS processos_emissao (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    agente_id BIGINT UNSIGNED NOT NULL,
    tipo_codigo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_codigo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    numero_protocolo VARCHAR(100) NULL,
    justificativa TEXT NULL,
    fato_em DATETIME(6) NULL,
    comunicado_em DATETIME(6) NULL,
    elegivel_emissao_em DATETIME(6) NULL,
    criado_por BIGINT UNSIGNED NOT NULL,
    criado_em DATETIME(6) NOT NULL,
    aprovado_por BIGINT UNSIGNED NULL,
    aprovado_em DATETIME(6) NULL,
    cancelado_por BIGINT UNSIGNED NULL,
    cancelado_em DATETIME(6) NULL,
    atualizado_em DATETIME(6) NOT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_processos_uuid (uuid),
    KEY idx_processos_agente_status (agente_id, status_codigo, criado_em),
    KEY idx_processos_protocolo (numero_protocolo),
    CONSTRAINT fk_processos_agente FOREIGN KEY (agente_id) REFERENCES agentes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_processos_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_processos_aprovado_por FOREIGN KEY (aprovado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_processos_cancelado_por FOREIGN KEY (cancelado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS processos_documentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    processo_id BIGINT UNSIGNED NOT NULL,
    arquivo_id BIGINT UNSIGNED NOT NULL,
    tipo_documento VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    numero_documento VARCHAR(100) NULL,
    emitido_em DATE NULL,
    incluido_por BIGINT UNSIGNED NOT NULL,
    incluido_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_processos_documentos_arquivo (processo_id, arquivo_id),
    KEY idx_processos_documentos_tipo (processo_id, tipo_documento),
    CONSTRAINT fk_processos_documentos_processo FOREIGN KEY (processo_id) REFERENCES processos_emissao (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_processos_documentos_arquivo FOREIGN KEY (arquivo_id) REFERENCES arquivos_privados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_processos_documentos_usuario FOREIGN KEY (incluido_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS processos_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    processo_id BIGINT UNSIGNED NOT NULL,
    tipo_evento VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    estado_anterior VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
    estado_novo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
    dados JSON NULL,
    usuario_id BIGINT UNSIGNED NULL,
    ocorrido_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_processos_eventos_uuid (uuid),
    KEY idx_processos_eventos_processo_data (processo_id, ocorrido_em),
    KEY idx_processos_eventos_tipo_data (tipo_evento, ocorrido_em),
    CONSTRAINT fk_processos_eventos_processo FOREIGN KEY (processo_id) REFERENCES processos_emissao (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_processos_eventos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS validacoes_dados_biograficos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agente_id BIGINT UNSIGNED NOT NULL,
    dados_biograficos_id BIGINT UNSIGNED NOT NULL,
    processo_emissao_id BIGINT UNSIGNED NULL,
    validado_pelo_titular TINYINT(1) NOT NULL,
    metodo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    validado_em DATETIME(6) NOT NULL,
    registrado_por BIGINT UNSIGNED NOT NULL,
    termo_arquivo_id BIGINT UNSIGNED NULL,
    observacoes TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_validacoes_agente_data (agente_id, validado_em),
    KEY idx_validacoes_processo (processo_emissao_id),
    CONSTRAINT fk_validacoes_agente FOREIGN KEY (agente_id) REFERENCES agentes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_validacoes_dados FOREIGN KEY (dados_biograficos_id) REFERENCES agentes_dados_biograficos (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_validacoes_processo FOREIGN KEY (processo_emissao_id) REFERENCES processos_emissao (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_validacoes_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_validacoes_termo FOREIGN KEY (termo_arquivo_id) REFERENCES arquivos_privados (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS ciclos_emissao (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    numero TINYINT UNSIGNED NOT NULL,
    descricao VARCHAR(200) NULL,
    ato_referencia VARCHAR(200) NULL,
    justificativa TEXT NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aberto_por BIGINT UNSIGNED NOT NULL,
    aberto_em DATETIME(6) NOT NULL,
    encerrado_por BIGINT UNSIGNED NULL,
    encerrado_em DATETIME(6) NULL,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ciclos_uuid (uuid),
    UNIQUE KEY uq_ciclos_numero (numero),
    KEY idx_ciclos_status (status),
    CONSTRAINT fk_ciclos_aberto_por FOREIGN KEY (aberto_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ciclos_encerrado_por FOREIGN KEY (encerrado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS controle_numeracao_cif (
    id TINYINT UNSIGNED NOT NULL,
    ciclo_ativo_id BIGINT UNSIGNED NULL,
    ultimo_sequencial INT UNSIGNED NOT NULL DEFAULT 0,
    atualizado_em DATETIME(6) NOT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_controle_ciclo_ativo (ciclo_ativo_id),
    CONSTRAINT fk_controle_ciclo FOREIGN KEY (ciclo_ativo_id) REFERENCES ciclos_emissao (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT IGNORE INTO controle_numeracao_cif (id, ciclo_ativo_id, ultimo_sequencial, atualizado_em, lock_version)
VALUES (1, NULL, 0, UTC_TIMESTAMP(6), 0)
SQL,
    ],
];
