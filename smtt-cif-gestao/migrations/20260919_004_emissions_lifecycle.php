<?php

declare(strict_types=1);

return [
    'version' => '20260919_004_emissions_lifecycle',
    'description' => 'Emissões imutáveis, snapshots, estados, eventos, ocorrências, recolhimentos e artefatos.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS emissoes_cif (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    generation_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    agente_id BIGINT UNSIGNED NOT NULL,
    processo_id BIGINT UNSIGNED NOT NULL,
    emissao_anterior_id BIGINT UNSIGNED NULL,
    tipo_emissao_codigo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ciclo_id BIGINT UNSIGNED NOT NULL,
    sequencial_sistema INT UNSIGNED NOT NULL,
    codigo_validacao CHAR(13) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    regra_codigo_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    qr_token_hash BINARY(32) NOT NULL,
    qr_token_ciphertext VARBINARY(1024) NOT NULL,
    qr_key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    qr_token_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    signatario_id BIGINT UNSIGNED NOT NULL,
    template_version VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    emitida_por BIGINT UNSIGNED NOT NULL,
    emitida_em DATETIME(6) NOT NULL,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emissoes_uuid (uuid),
    UNIQUE KEY uq_emissoes_generation_key (generation_key),
    UNIQUE KEY uq_emissoes_sequencial (sequencial_sistema),
    UNIQUE KEY uq_emissoes_codigo (codigo_validacao),
    UNIQUE KEY uq_emissoes_qr_hash (qr_token_hash),
    UNIQUE KEY uq_emissoes_id_agente (id, agente_id),
    KEY idx_emissoes_agente_data (agente_id, emitida_em),
    KEY idx_emissoes_processo (processo_id),
    KEY idx_emissoes_ciclo (ciclo_id, sequencial_sistema),
    CONSTRAINT fk_emissoes_agente FOREIGN KEY (agente_id) REFERENCES agentes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_emissoes_processo FOREIGN KEY (processo_id) REFERENCES processos_emissao (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_emissoes_anterior FOREIGN KEY (emissao_anterior_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_emissoes_ciclo FOREIGN KEY (ciclo_id) REFERENCES ciclos_emissao (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_emissoes_signatario FOREIGN KEY (signatario_id) REFERENCES signatarios_institucionais (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_emissoes_emitida_por FOREIGN KEY (emitida_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS processos_emissoes_origem (
    processo_id BIGINT UNSIGNED NOT NULL,
    emissao_origem_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (processo_id),
    KEY idx_processos_origem_emissao (emissao_origem_id),
    CONSTRAINT fk_processos_origem_processo FOREIGN KEY (processo_id) REFERENCES processos_emissao (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_processos_origem_emissao FOREIGN KEY (emissao_origem_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS emissoes_cif_snapshot (
    emissao_id BIGINT UNSIGNED NOT NULL,
    dados_biograficos_id BIGINT UNSIGNED NOT NULL,
    nome_completo VARCHAR(200) NOT NULL,
    matricula VARCHAR(30) NOT NULL,
    naturalidade VARCHAR(150) NOT NULL,
    data_nascimento DATE NOT NULL,
    cpf_ciphertext VARBINARY(1024) NOT NULL,
    cpf_key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tipo_sanguineo VARCHAR(10) NULL,
    data_admissao DATE NULL,
    situacao_funcional VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    signatario_nome VARCHAR(200) NOT NULL,
    signatario_cargo VARCHAR(150) NOT NULL,
    template_version VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dados_extras JSON NULL,
    snapshot_hash BINARY(32) NOT NULL,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (emissao_id),
    KEY idx_snapshot_dados_biograficos (dados_biograficos_id),
    CONSTRAINT fk_snapshot_emissao FOREIGN KEY (emissao_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_snapshot_dados FOREIGN KEY (dados_biograficos_id) REFERENCES agentes_dados_biograficos (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS emissoes_cif_biometrias (
    emissao_id BIGINT UNSIGNED NOT NULL,
    biometria_id BIGINT UNSIGNED NOT NULL,
    finalidade VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (emissao_id, finalidade),
    UNIQUE KEY uq_emissao_biometria_finalidade (emissao_id, biometria_id, finalidade),
    KEY idx_emissoes_biometrias_biometria (biometria_id),
    CONSTRAINT fk_emissoes_biometrias_emissao FOREIGN KEY (emissao_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_emissoes_biometrias_biometria FOREIGN KEY (biometria_id) REFERENCES biometrias (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS emissoes_cif_estado (
    emissao_id BIGINT UNSIGNED NOT NULL,
    status_validade VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    motivo_suspensao VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
    suspensa_em DATETIME(6) NULL,
    motivo_encerramento VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
    encerrada_em DATETIME(6) NULL,
    status_fisico VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ativada_em DATETIME(6) NULL,
    entregue_em DATETIME(6) NULL,
    public_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    atualizado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (emissao_id),
    KEY idx_estado_validade (status_validade, atualizado_em),
    KEY idx_estado_fisico (status_fisico, atualizado_em),
    CONSTRAINT fk_estado_emissao FOREIGN KEY (emissao_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS agentes_emissoes_ativas (
    agente_id BIGINT UNSIGNED NOT NULL,
    emissao_id BIGINT UNSIGNED NOT NULL,
    vinculada_em DATETIME(6) NOT NULL,
    PRIMARY KEY (agente_id),
    UNIQUE KEY uq_agente_emissao_ativa_emissao (emissao_id),
    CONSTRAINT fk_ativas_agente FOREIGN KEY (agente_id) REFERENCES agentes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ativas_emissao_agente FOREIGN KEY (emissao_id, agente_id) REFERENCES emissoes_cif (id, agente_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS eventos_cif (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    emissao_id BIGINT UNSIGNED NOT NULL,
    processo_id BIGINT UNSIGNED NULL,
    tipo_evento VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_anterior VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status_novo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
    dados JSON NULL,
    usuario_id BIGINT UNSIGNED NULL,
    ocorrido_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_eventos_cif_uuid (uuid),
    KEY idx_eventos_cif_emissao_data (emissao_id, ocorrido_em),
    KEY idx_eventos_cif_tipo_data (tipo_evento, ocorrido_em),
    CONSTRAINT fk_eventos_cif_emissao FOREIGN KEY (emissao_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_eventos_cif_processo FOREIGN KEY (processo_id) REFERENCES processos_emissao (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_eventos_cif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS ocorrencias_cif (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    agente_id BIGINT UNSIGNED NOT NULL,
    emissao_id BIGINT UNSIGNED NOT NULL,
    processo_id BIGINT UNSIGNED NULL,
    tipo_codigo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    data_fato DATETIME(6) NULL,
    data_comunicacao DATETIME(6) NOT NULL,
    bo_numero VARCHAR(100) NULL,
    bo_data DATE NULL,
    bo_orgao VARCHAR(150) NULL,
    recuperada_em DATETIME(6) NULL,
    detalhes TEXT NULL,
    registrado_por BIGINT UNSIGNED NOT NULL,
    registrado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ocorrencias_uuid (uuid),
    KEY idx_ocorrencias_emissao_data (emissao_id, data_comunicacao),
    KEY idx_ocorrencias_agente_tipo (agente_id, tipo_codigo),
    CONSTRAINT fk_ocorrencias_agente FOREIGN KEY (agente_id) REFERENCES agentes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ocorrencias_emissao FOREIGN KEY (emissao_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ocorrencias_processo FOREIGN KEY (processo_id) REFERENCES processos_emissao (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ocorrencias_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS ocorrencias_documentos (
    ocorrencia_id BIGINT UNSIGNED NOT NULL,
    arquivo_id BIGINT UNSIGNED NOT NULL,
    tipo_documento VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    incluido_por BIGINT UNSIGNED NOT NULL,
    incluido_em DATETIME(6) NOT NULL,
    PRIMARY KEY (ocorrencia_id, arquivo_id),
    KEY idx_ocorrencias_documentos_arquivo (arquivo_id),
    CONSTRAINT fk_ocorrencias_documentos_ocorrencia FOREIGN KEY (ocorrencia_id) REFERENCES ocorrencias_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ocorrencias_documentos_arquivo FOREIGN KEY (arquivo_id) REFERENCES arquivos_privados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ocorrencias_documentos_usuario FOREIGN KEY (incluido_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS recolhimentos_cif (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    emissao_id BIGINT UNSIGNED NOT NULL,
    processo_id BIGINT UNSIGNED NULL,
    motivo_codigo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    fato_em DATETIME(6) NOT NULL,
    recolhimento_solicitado_em DATETIME(6) NULL,
    recolhimento_devido_em DATETIME(6) NULL,
    recolhida_em DATETIME(6) NULL,
    inutilizacao_permitida_em DATETIME(6) NULL,
    inutilizada_em DATETIME(6) NULL,
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    motivo_nao_recolhimento TEXT NULL,
    registrado_por BIGINT UNSIGNED NOT NULL,
    criado_em DATETIME(6) NOT NULL,
    atualizado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recolhimentos_uuid (uuid),
    KEY idx_recolhimentos_emissao_status (emissao_id, status),
    KEY idx_recolhimentos_prazo (status, inutilizacao_permitida_em),
    CONSTRAINT fk_recolhimentos_emissao FOREIGN KEY (emissao_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_recolhimentos_processo FOREIGN KEY (processo_id) REFERENCES processos_emissao (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_recolhimentos_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS artefatos_emissao (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    emissao_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    versao INT UNSIGNED NOT NULL,
    arquivo_id BIGINT UNSIGNED NOT NULL,
    template_version VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
    sha256 BINARY(32) NOT NULL,
    gerado_por BIGINT UNSIGNED NOT NULL,
    gerado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_artefatos_emissao_tipo_versao (emissao_id, tipo, versao),
    UNIQUE KEY uq_artefatos_arquivo (arquivo_id),
    CONSTRAINT fk_artefatos_emissao FOREIGN KEY (emissao_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_artefatos_arquivo FOREIGN KEY (arquivo_id) REFERENCES arquivos_privados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_artefatos_usuario FOREIGN KEY (gerado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS impressoes_cif (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    emissao_id BIGINT UNSIGNED NOT NULL,
    artefato_id BIGINT UNSIGNED NOT NULL,
    tentativa SMALLINT UNSIGNED NOT NULL,
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    motivo VARCHAR(100) NULL,
    impressora VARCHAR(150) NULL,
    solicitada_por BIGINT UNSIGNED NOT NULL,
    solicitada_em DATETIME(6) NOT NULL,
    concluida_em DATETIME(6) NULL,
    erro VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_impressoes_emissao_tentativa (emissao_id, tentativa),
    KEY idx_impressoes_status_data (status, solicitada_em),
    CONSTRAINT fk_impressoes_emissao FOREIGN KEY (emissao_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_impressoes_artefato FOREIGN KEY (artefato_id) REFERENCES artefatos_emissao (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_impressoes_usuario FOREIGN KEY (solicitada_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
