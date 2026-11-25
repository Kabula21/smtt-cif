<?php

declare(strict_types=1);

return [
    'version' => '20260919_002_agents_biometrics',
    'description' => 'Agentes, dados biográficos versionados, arquivos privados, biometria e signatários institucionais.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS agentes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    matricula VARCHAR(30) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    situacao_funcional_atual VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    criado_em DATETIME(6) NOT NULL,
    atualizado_em DATETIME(6) NOT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agentes_uuid (uuid),
    UNIQUE KEY uq_agentes_matricula (matricula),
    KEY idx_agentes_situacao_ativo (situacao_funcional_atual, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS arquivos_privados (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    categoria VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    storage_backend VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    storage_key VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nome_original VARCHAR(255) NULL,
    mime_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tamanho_bytes BIGINT UNSIGNED NOT NULL,
    sha256 BINARY(32) NOT NULL,
    criptografado TINYINT(1) NOT NULL DEFAULT 0,
    key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    criado_por BIGINT UNSIGNED NULL,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_arquivos_uuid (uuid),
    UNIQUE KEY uq_arquivos_storage_key (storage_key),
    KEY idx_arquivos_categoria_status (categoria, status),
    KEY idx_arquivos_sha256 (sha256),
    CONSTRAINT fk_arquivos_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS agentes_dados_biograficos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agente_id BIGINT UNSIGNED NOT NULL,
    versao INT UNSIGNED NOT NULL,
    nome_completo VARCHAR(200) NOT NULL,
    matricula_snapshot VARCHAR(30) NOT NULL,
    naturalidade VARCHAR(150) NOT NULL,
    data_nascimento DATE NOT NULL,
    cpf_lookup_hmac BINARY(32) NOT NULL,
    cpf_ciphertext VARBINARY(1024) NOT NULL,
    cpf_key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tipo_sanguineo VARCHAR(10) NULL,
    data_admissao DATE NULL,
    fonte VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    fonte_referencia VARCHAR(150) NULL,
    fonte_atualizada_em DATETIME(6) NULL,
    criado_por BIGINT UNSIGNED NOT NULL,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_biograficos_agente_versao (agente_id, versao),
    KEY idx_biograficos_cpf_hmac (cpf_lookup_hmac),
    CONSTRAINT fk_biograficos_agente FOREIGN KEY (agente_id) REFERENCES agentes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_biograficos_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS agentes_situacoes_funcionais (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agente_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    inicio_em DATETIME(6) NOT NULL,
    fim_em DATETIME(6) NULL,
    fonte VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    fonte_referencia VARCHAR(150) NULL,
    registrado_por BIGINT UNSIGNED NOT NULL,
    registrado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_situacoes_agente_periodo (agente_id, fim_em, inicio_em),
    CONSTRAINT fk_situacoes_agente FOREIGN KEY (agente_id) REFERENCES agentes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_situacoes_registrado_por FOREIGN KEY (registrado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS coletas_biometricas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    agente_id BIGINT UNSIGNED NOT NULL,
    coletada_em DATETIME(6) NOT NULL,
    reutilizavel_ate DATE NULL,
    operador_id BIGINT UNSIGNED NOT NULL,
    local_coleta VARCHAR(150) NULL,
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observacoes TEXT NULL,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coletas_uuid (uuid),
    KEY idx_coletas_agente_data (agente_id, coletada_em),
    CONSTRAINT fk_coletas_agente FOREIGN KEY (agente_id) REFERENCES agentes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_coletas_operador FOREIGN KEY (operador_id) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS biometrias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    coleta_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    arquivo_id BIGINT UNSIGNED NOT NULL,
    dpi SMALLINT UNSIGNED NOT NULL,
    largura_px INT UNSIGNED NULL,
    altura_px INT UNSIGNED NULL,
    conforme_padrao TINYINT(1) NOT NULL DEFAULT 0,
    observacoes TEXT NULL,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_biometrias_coleta_tipo (coleta_id, tipo),
    UNIQUE KEY uq_biometrias_arquivo (arquivo_id),
    CONSTRAINT fk_biometrias_coleta FOREIGN KEY (coleta_id) REFERENCES coletas_biometricas (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_biometrias_arquivo FOREIGN KEY (arquivo_id) REFERENCES arquivos_privados (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS signatarios_institucionais (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nome VARCHAR(200) NOT NULL,
    cargo VARCHAR(150) NOT NULL,
    assinatura_arquivo_id BIGINT UNSIGNED NOT NULL,
    vigencia_inicio DATETIME(6) NOT NULL,
    vigencia_fim DATETIME(6) NULL,
    ato_referencia VARCHAR(200) NULL,
    criado_por BIGINT UNSIGNED NOT NULL,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_signatarios_uuid (uuid),
    KEY idx_signatarios_vigencia (vigencia_inicio, vigencia_fim),
    CONSTRAINT fk_signatarios_assinatura FOREIGN KEY (assinatura_arquivo_id) REFERENCES arquivos_privados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_signatarios_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
