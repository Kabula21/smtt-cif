<?php

declare(strict_types=1);

return [
    'version' => '20260919_010_agent_bulk_permissions',
    'description' => 'Permissões específicas para importação e exportação controlada do cadastro de agentes.',
    'up' => [
        <<<'SQL'
INSERT INTO permissoes (codigo, nome, descricao, modulo, ativo, criado_em) VALUES
('cif.agente.importar', 'Importar agentes', 'Permite preparar, revisar e confirmar lotes de importação de Agentes de Trânsito.', 'agente', 1, UTC_TIMESTAMP(6)),
('cif.agente.exportar', 'Exportar agentes', 'Permite exportar a listagem filtrada de agentes sem CPF e sem biometria.', 'agente', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao), modulo = VALUES(modulo), ativo = VALUES(ativo)
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo = 'cif.agente.importar'
WHERE p.codigo IN ('ADMIN_CIF')
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo = 'cif.agente.exportar'
WHERE p.codigo IN ('ADMIN_CIF', 'EDITOR_CIF')
SQL,
    ],
];
