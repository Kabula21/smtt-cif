<?php

declare(strict_types=1);

return [
    'version' => '20260919_008_sensitive_agent_permission',
    'description' => 'Permissão separada para visualização de dados pessoais sensíveis do cadastro de agentes.',
    'up' => [
        <<<'SQL'
INSERT INTO permissoes (codigo, nome, descricao, modulo, ativo, criado_em) VALUES
('cif.agente.visualizar_dados_sensiveis', 'Visualizar dados pessoais protegidos do agente', 'Permite visualizar campos protegidos, como o CPF completo, no cadastro interno.', 'agente', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao), modulo = VALUES(modulo), ativo = VALUES(ativo)
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo = 'cif.agente.visualizar_dados_sensiveis'
WHERE p.codigo IN ('ADMIN_CIF', 'EDITOR_CIF')
SQL,
    ],
];
