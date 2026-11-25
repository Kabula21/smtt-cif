<?php

declare(strict_types=1);

return [
    'version' => '20260920_017_delivery_activation',
    'description' => 'Registro imutável da entrega/ativação da CIF e vínculo com eventual emissão anterior.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS entregas_cif (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    emissao_id BIGINT UNSIGNED NOT NULL,
    emissao_anterior_id BIGINT UNSIGNED NULL,
    titular_confirmou TINYINT(1) NOT NULL,
    metodo_confirmacao VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    referencia VARCHAR(200) NULL,
    anterior_recolhida TINYINT(1) NOT NULL DEFAULT 0,
    observacoes TEXT NULL,
    entregue_por BIGINT UNSIGNED NOT NULL,
    entregue_em DATETIME(6) NOT NULL,
    criado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_entregas_uuid (uuid),
    UNIQUE KEY uq_entregas_emissao (emissao_id),
    KEY idx_entregas_data (entregue_em),
    KEY idx_entregas_anterior (emissao_anterior_id),
    CONSTRAINT fk_entregas_emissao FOREIGN KEY (emissao_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_entregas_anterior FOREIGN KEY (emissao_anterior_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_entregas_usuario FOREIGN KEY (entregue_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
