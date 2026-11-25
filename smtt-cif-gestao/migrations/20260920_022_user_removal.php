<?php

declare(strict_types=1);

return [
    'version' => '20260920_022_user_removal',
    'description' => 'Permite remoção lógica de usuários CIF independentemente da existência da conta WordPress, preservando histórico e auditoria.',
    'up' => [
        <<<'SQL'
ALTER TABLE usuarios_cif
    ADD COLUMN removido_em DATETIME(6) NULL AFTER atualizado_em,
    ADD COLUMN removido_por BIGINT UNSIGNED NULL AFTER removido_em,
    ADD KEY idx_usuarios_cif_removido_em (removido_em),
    ADD KEY idx_usuarios_cif_removido_por (removido_por),
    ADD CONSTRAINT fk_usuarios_cif_removido_por FOREIGN KEY (removido_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
    ],
];
