<?php

declare(strict_types=1);

return [
    'version' => '20260920_020_ux_agent_management',
    'description' => 'Permissão administrativa para exclusão segura de cadastro de agente sem vínculos históricos.',
    'up' => [
        <<<'SQL'
INSERT INTO permissoes (codigo, nome, descricao, modulo, ativo, criado_em) VALUES
('cif.agente.excluir', 'Excluir cadastro de agente sem vínculos', 'Permite excluir definitivamente apenas cadastros de agentes que ainda não possuam biometria, processo, emissão, ocorrência ou vínculo de importação.', 'agente', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao), modulo = VALUES(modulo), ativo = VALUES(ativo)
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo = 'cif.agente.excluir'
WHERE p.codigo = 'ADMIN_CIF'
SQL,
    ],
];
