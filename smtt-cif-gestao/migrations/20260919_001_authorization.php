<?php

declare(strict_types=1);

return [
    'version' => '20260919_001_authorization',
    'description' => 'Usuários CIF, perfis e permissões internas independentes das roles do WordPress.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS usuarios_cif (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    wp_user_id BIGINT UNSIGNED NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    login_snapshot VARCHAR(100) NOT NULL,
    nome_snapshot VARCHAR(200) NOT NULL,
    ultimo_acesso_em DATETIME(6) NULL,
    criado_em DATETIME(6) NOT NULL,
    atualizado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_cif_uuid (uuid),
    UNIQUE KEY uq_usuarios_cif_wp_user (wp_user_id),
    KEY idx_usuarios_cif_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS perfis_acesso (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nome VARCHAR(100) NOT NULL,
    descricao VARCHAR(500) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    sistema TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME(6) NOT NULL,
    atualizado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_perfis_codigo (codigo),
    KEY idx_perfis_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS permissoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nome VARCHAR(150) NOT NULL,
    descricao VARCHAR(500) NULL,
    modulo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissoes_codigo (codigo),
    KEY idx_permissoes_modulo_ativo (modulo, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS usuario_perfis (
    usuario_id BIGINT UNSIGNED NOT NULL,
    perfil_id BIGINT UNSIGNED NOT NULL,
    concedido_por BIGINT UNSIGNED NULL,
    concedido_em DATETIME(6) NOT NULL,
    PRIMARY KEY (usuario_id, perfil_id),
    KEY idx_usuario_perfis_perfil (perfil_id),
    CONSTRAINT fk_usuario_perfis_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_usuario_perfis_perfil FOREIGN KEY (perfil_id) REFERENCES perfis_acesso (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_usuario_perfis_concedente FOREIGN KEY (concedido_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS perfil_permissoes (
    perfil_id BIGINT UNSIGNED NOT NULL,
    permissao_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (perfil_id, permissao_id),
    KEY idx_perfil_permissoes_permissao (permissao_id),
    CONSTRAINT fk_perfil_permissoes_perfil FOREIGN KEY (perfil_id) REFERENCES perfis_acesso (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_perfil_permissoes_permissao FOREIGN KEY (permissao_id) REFERENCES permissoes (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS usuario_permissoes (
    usuario_id BIGINT UNSIGNED NOT NULL,
    permissao_id BIGINT UNSIGNED NOT NULL,
    efeito VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    concedido_por BIGINT UNSIGNED NULL,
    concedido_em DATETIME(6) NOT NULL,
    PRIMARY KEY (usuario_id, permissao_id),
    KEY idx_usuario_permissoes_permissao (permissao_id),
    CONSTRAINT fk_usuario_permissoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_usuario_permissoes_permissao FOREIGN KEY (permissao_id) REFERENCES permissoes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_usuario_permissoes_concedente FOREIGN KEY (concedido_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
