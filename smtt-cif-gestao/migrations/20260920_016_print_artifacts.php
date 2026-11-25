<?php

declare(strict_types=1);

return [
    'version' => '20260920_016_print_artifacts',
    'description' => 'Destino QR imutável por emissão e suporte operacional a artefatos/impressão.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS emissoes_qr_destinos (
    emissao_id BIGINT UNSIGNED NOT NULL,
    base_url VARCHAR(500) NOT NULL,
    url_hash BINARY(32) NOT NULL,
    definida_por BIGINT UNSIGNED NOT NULL,
    definida_em DATETIME(6) NOT NULL,
    PRIMARY KEY (emissao_id),
    UNIQUE KEY uq_emissoes_qr_url_hash (url_hash),
    CONSTRAINT fk_qr_destino_emissao FOREIGN KEY (emissao_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_qr_destino_usuario FOREIGN KEY (definida_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
