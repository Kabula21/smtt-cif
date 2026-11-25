<?php

declare(strict_types=1);

return [
    'version' => '20260919_014_process_module',
    'description' => 'Permissões específicas do módulo operacional de processos de emissão.',
    'up' => [
        <<<'SQL'
INSERT INTO permissoes (codigo, nome, descricao, modulo, ativo, criado_em) VALUES
('cif.processo.validar_dados', 'Registrar validação dos dados pelo titular', 'Registra a conferência da versão biográfica atual pelo titular da CIF.', 'processo', 1, UTC_TIMESTAMP(6)),
('cif.processo.anexar_documentos', 'Anexar documentos ao processo', 'Inclui documentos privados no processo administrativo de emissão.', 'processo', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao), modulo = VALUES(modulo), ativo = VALUES(ativo)
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo IN (
    'cif.processo.validar_dados',
    'cif.processo.anexar_documentos'
)
WHERE p.codigo IN ('ADMIN_CIF', 'EDITOR_CIF')
SQL,
    ],
];
