<?php

declare(strict_types=1);

return [
    'version' => '20260920_019_sync_worker',
    'description' => 'Permissão específica para configuração segura da sincronização interno → público.',
    'up' => [
        <<<'SQL'
INSERT INTO permissoes (codigo, nome, descricao, modulo, ativo, criado_em) VALUES
('cif.sincronizacao.configurar', 'Configurar sincronização', 'Permite configurar o portal público e administrar chaves de assinatura Ed25519.', 'sincronizacao', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE nome=VALUES(nome), descricao=VALUES(descricao), modulo=VALUES(modulo), ativo=VALUES(ativo)
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo = 'cif.sincronizacao.configurar'
WHERE p.codigo = 'ADMIN_CIF'
SQL,
    ],
];
