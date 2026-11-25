<?php

declare(strict_types=1);

return [
    'version' => '20260919_009_agent_imports',
    'description' => 'Lotes e itens de importação de agentes, sem persistir o arquivo de origem nem CPF em texto puro.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS importacoes_agentes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL,
    formato VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    arquivo_sha256 BINARY(32) NOT NULL,
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    total_linhas INT UNSIGNED NOT NULL DEFAULT 0,
    linhas_prontas INT UNSIGNED NOT NULL DEFAULT 0,
    linhas_duplicadas INT UNSIGNED NOT NULL DEFAULT 0,
    linhas_com_erro INT UNSIGNED NOT NULL DEFAULT 0,
    linhas_importadas INT UNSIGNED NOT NULL DEFAULT 0,
    criado_por BIGINT UNSIGNED NOT NULL,
    criado_em DATETIME(6) NOT NULL,
    confirmado_em DATETIME(6) NULL,
    concluido_em DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_importacoes_agentes_uuid (uuid),
    KEY idx_importacoes_agentes_status_data (status, criado_em),
    CONSTRAINT fk_importacoes_agentes_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS importacoes_agentes_itens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    importacao_id BIGINT UNSIGNED NOT NULL,
    numero_linha INT UNSIGNED NOT NULL,
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    matricula VARCHAR(30) NULL,
    cpf_lookup_hmac BINARY(32) NULL,
    cpf_ciphertext VARBINARY(1024) NULL,
    cpf_key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    dados_json JSON NULL,
    codigo_erro VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
    mensagem_erro VARCHAR(500) NULL,
    agente_id BIGINT UNSIGNED NULL,
    criado_em DATETIME(6) NOT NULL,
    processado_em DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_importacoes_agentes_itens_linha (importacao_id, numero_linha),
    KEY idx_importacoes_agentes_itens_status (importacao_id, status),
    KEY idx_importacoes_agentes_itens_matricula (matricula),
    KEY idx_importacoes_agentes_itens_cpf (cpf_lookup_hmac),
    CONSTRAINT fk_importacoes_agentes_itens_lote FOREIGN KEY (importacao_id) REFERENCES importacoes_agentes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_importacoes_agentes_itens_agente FOREIGN KEY (agente_id) REFERENCES agentes (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
