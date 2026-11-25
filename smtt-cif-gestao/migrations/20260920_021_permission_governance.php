<?php

declare(strict_types=1);

return [
    'version' => '20260920_021_permission_governance',
    'description' => 'Reforça a matriz de permissões: ADMIN_CIF integral, remoções restritas e perfis operacionais sem capacidades administrativas críticas.',
    'up' => [
        <<<'SQL'
INSERT INTO permissoes (codigo, nome, descricao, modulo, ativo, criado_em)
VALUES ('cif.seguranca.remover_usuarios', 'Remover acesso de usuários CIF', 'Permite remover o acesso operacional CIF de outro usuário, preservando conta WordPress e histórico.', 'seguranca', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao), modulo = VALUES(modulo), ativo = VALUES(ativo)
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
CROSS JOIN permissoes perm
WHERE p.codigo = 'ADMIN_CIF' AND p.ativo = 1 AND perm.ativo = 1
SQL,
        <<<'SQL'
DELETE pp
FROM perfil_permissoes pp
JOIN perfis_acesso p ON p.id = pp.perfil_id
JOIN permissoes perm ON perm.id = pp.permissao_id
WHERE p.codigo IN ('EDITOR_CIF', 'CONSULTA_CIF')
  AND perm.codigo IN (
    'cif.agente.excluir',
    'cif.biometria.invalidar',
    'cif.processo.aprovar',
    'cif.processo.cancelar',
    'cif.emissao.gerar',
    'cif.emissao.ativar',
    'cif.emissao.entregar',
    'cif.emissao.imprimir',
    'cif.emissao.cancelar',
    'cif.emissao.visualizar_token_qr',
    'cif.ocorrencia.registrar',
    'cif.ocorrencia.recuperar',
    'cif.recolhimento.registrar',
    'cif.recolhimento.inutilizar',
    'cif.sincronizacao.reenviar',
    'cif.sincronizacao.reconciliar',
    'cif.sincronizacao.reconstruir',
    'cif.sincronizacao.configurar',
    'cif.numeracao.gerenciar_ciclo',
    'cif.signatario.gerenciar',
    'cif.configuracao.editar',
    'cif.seguranca.gerenciar_usuarios',
    'cif.seguranca.gerenciar_perfis',
    'cif.seguranca.remover_usuarios'
  )
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo IN (
    'cif.agente.visualizar',
    'cif.processo.visualizar',
    'cif.emissao.visualizar',
    'cif.ocorrencia.visualizar',
    'cif.recolhimento.visualizar',
    'cif.relatorio.visualizar'
)
WHERE p.codigo = 'CONSULTA_CIF' AND p.ativo = 1 AND perm.ativo = 1
SQL,
    ],
];
