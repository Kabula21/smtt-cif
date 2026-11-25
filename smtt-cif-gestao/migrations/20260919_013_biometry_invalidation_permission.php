<?php

declare(strict_types=1);

return [
    'version' => '20260919_013_biometry_invalidation_permission',
    'description' => 'Permissão específica para invalidar coleta biométrica preservando o histórico.',
    'up' => [
        <<<'SQL'
INSERT INTO permissoes (codigo, nome, descricao, modulo, ativo, criado_em) VALUES
('cif.biometria.invalidar', 'Invalidar coleta biométrica', 'Permite invalidar uma coleta biométrica não utilizada em emissão, mediante justificativa obrigatória.', 'biometria', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao), modulo = VALUES(modulo), ativo = VALUES(ativo)
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo = 'cif.biometria.invalidar'
WHERE p.codigo IN ('ADMIN_CIF')
SQL,
    ],
];
