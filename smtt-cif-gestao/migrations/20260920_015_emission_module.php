<?php

declare(strict_types=1);

return [
    'version' => '20260920_015_emission_module',
    'description' => 'Permissões específicas da preparação institucional e geração de emissões CIF.',
    'up' => [
        <<<'SQL'
INSERT INTO permissoes (codigo, nome, descricao, modulo, ativo, criado_em) VALUES
('cif.emissao.visualizar_token_qr', 'Visualizar token QR da emissão', 'Permite consultar o token aleatório integral usado para formar o QR da emissão.', 'emissao', 1, UTC_TIMESTAMP(6)),
('cif.signatario.visualizar', 'Visualizar signatários institucionais', 'Consulta os signatários e assinaturas institucionais cadastrados para emissão da CIF.', 'emissao', 1, UTC_TIMESTAMP(6)),
('cif.signatario.gerenciar', 'Gerenciar signatários institucionais', 'Cadastra nova versão do signatário institucional utilizado na CIF.', 'emissao', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao), modulo = VALUES(modulo), ativo = VALUES(ativo)
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo IN (
    'cif.signatario.visualizar',
    'cif.signatario.gerenciar',
    'cif.numeracao.visualizar',
    'cif.numeracao.gerenciar_ciclo',
    'cif.emissao.visualizar',
    'cif.emissao.gerar',
    'cif.emissao.visualizar_token_qr'
)
WHERE p.codigo = 'ADMIN_CIF'
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo IN (
    'cif.signatario.visualizar',
    'cif.numeracao.visualizar',
    'cif.emissao.visualizar'
)
WHERE p.codigo = 'EDITOR_CIF'
SQL,
    ],
];
