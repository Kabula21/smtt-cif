<?php

declare(strict_types=1);

return [
    'version' => '20260924_025_native_cif_auth',
    'description' => 'Autenticação nativa do Sistema CIF, usuários independentes do WordPress e sessões próprias.',
    'up' => [
        <<<'SQL'
ALTER TABLE usuarios_cif
    MODIFY COLUMN wp_user_id BIGINT UNSIGNED NULL,
    ADD COLUMN login VARCHAR(100) NULL AFTER wp_user_id,
    ADD COLUMN email VARCHAR(190) NULL AFTER login,
    ADD COLUMN setor VARCHAR(150) NULL AFTER email,
    ADD COLUMN senha_hash VARCHAR(255) NULL AFTER setor,
    ADD COLUMN deve_trocar_senha TINYINT(1) NOT NULL DEFAULT 1 AFTER senha_hash,
    ADD COLUMN falhas_login SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER deve_trocar_senha,
    ADD COLUMN bloqueado_ate DATETIME(6) NULL AFTER falhas_login,
    ADD COLUMN ultimo_login_em DATETIME(6) NULL AFTER bloqueado_ate
SQL,
        <<<'SQL'
UPDATE usuarios_cif
SET login = login_snapshot
WHERE login IS NULL OR login = ''
SQL,
        <<<'SQL'
ALTER TABLE usuarios_cif
    MODIFY COLUMN login VARCHAR(100) NOT NULL,
    ADD UNIQUE KEY uq_usuarios_cif_login (login),
    ADD UNIQUE KEY uq_usuarios_cif_email (email),
    ADD KEY idx_usuarios_cif_setor (setor),
    ADD KEY idx_usuarios_cif_bloqueado_ate (bloqueado_ate)
SQL,
        <<<'SQL'
UPDATE permissoes
SET descricao='Permite excluir definitivamente um usuário CIF somente quando não existir histórico; caso contrário, deve ser inativado.'
WHERE codigo='cif.seguranca.remover_usuarios'
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sessoes_cif (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    token_hash BINARY(32) NOT NULL,
    criada_em DATETIME(6) NOT NULL,
    ultimo_acesso_em DATETIME(6) NOT NULL,
    expira_em DATETIME(6) NOT NULL,
    revogada_em DATETIME(6) NULL,
    ip_origem VARBINARY(16) NULL,
    user_agent_hash BINARY(32) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessoes_cif_uuid (uuid),
    UNIQUE KEY uq_sessoes_cif_token_hash (token_hash),
    KEY idx_sessoes_cif_usuario_ativa (usuario_id, revogada_em, expira_em),
    KEY idx_sessoes_cif_expira (expira_em),
    CONSTRAINT fk_sessoes_cif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
