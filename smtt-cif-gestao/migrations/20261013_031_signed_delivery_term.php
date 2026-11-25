<?php

declare(strict_types=1);

return [
    'version' => '20261013_031_signed_delivery_term',
    'description' => 'Termos de entrega assinados por emissão, anexos privados criptografados e histórico de substituições.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS emissoes_termos_entrega (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    emissao_id BIGINT UNSIGNED NOT NULL,
    arquivo_id BIGINT UNSIGNED NOT NULL,
    assinaturas_conferidas TINYINT(1) NOT NULL DEFAULT 0,
    anexado_por BIGINT UNSIGNED NOT NULL,
    anexado_em DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emissoes_termos_arquivo (arquivo_id),
    KEY idx_emissoes_termos_emissao (emissao_id, id),
    CONSTRAINT fk_emissoes_termos_emissao FOREIGN KEY (emissao_id) REFERENCES emissoes_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_emissoes_termos_arquivo FOREIGN KEY (arquivo_id) REFERENCES arquivos_privados (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_emissoes_termos_usuario FOREIGN KEY (anexado_por) REFERENCES usuarios_cif (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
