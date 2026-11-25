<?php

declare(strict_types=1);

return [
    'version' => '20260924_029_profile_visibility_cleanup',
    'description' => 'Libera relatórios ao Editor CIF e restringe a preparação institucional de emissão ao Administrador CIF.',
    'up' => [
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo = 'cif.relatorio.visualizar'
WHERE p.codigo = 'EDITOR_CIF' AND p.ativo = 1 AND perm.ativo = 1
SQL,
        <<<'SQL'
DELETE pp
FROM perfil_permissoes pp
JOIN perfis_acesso p ON p.id = pp.perfil_id
JOIN permissoes perm ON perm.id = pp.permissao_id
WHERE p.codigo = 'EDITOR_CIF'
  AND perm.codigo IN ('cif.numeracao.visualizar', 'cif.signatario.visualizar')
SQL,
    ],
];
