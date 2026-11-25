<?php

declare(strict_types=1);

return [
    'version' => '20260919_006_authorization_catalog',
    'description' => 'Catálogo inicial de perfis e permissões internas do Sistema CIF.',
    'up' => [
        <<<'SQL'
INSERT INTO perfis_acesso (codigo, nome, descricao, ativo, sistema, criado_em, atualizado_em) VALUES
('ADMIN_CIF', 'Administrador CIF', 'Acesso administrativo integral ao Sistema CIF, sujeito às invariantes do domínio.', 1, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
('EDITOR_CIF', 'Editor CIF', 'Permissões operacionais restritas para cadastro e manutenção de dados permitidos.', 1, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
('CONSULTA_CIF', 'Consulta CIF', 'Acesso somente às consultas expressamente autorizadas.', 1, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao), ativo = VALUES(ativo), sistema = VALUES(sistema), atualizado_em = VALUES(atualizado_em)
SQL,
        <<<'SQL'
INSERT INTO permissoes (codigo, nome, descricao, modulo, ativo, criado_em) VALUES
('cif.agente.visualizar', 'Visualizar agentes', NULL, 'agente', 1, UTC_TIMESTAMP(6)),
('cif.agente.criar', 'Cadastrar agentes', NULL, 'agente', 1, UTC_TIMESTAMP(6)),
('cif.agente.editar', 'Editar cadastro de agentes', NULL, 'agente', 1, UTC_TIMESTAMP(6)),
('cif.biometria.visualizar', 'Visualizar biometria', NULL, 'biometria', 1, UTC_TIMESTAMP(6)),
('cif.biometria.capturar', 'Capturar/atualizar biometria', NULL, 'biometria', 1, UTC_TIMESTAMP(6)),
('cif.processo.visualizar', 'Visualizar processos de emissão', NULL, 'processo', 1, UTC_TIMESTAMP(6)),
('cif.processo.criar', 'Criar processos de emissão', NULL, 'processo', 1, UTC_TIMESTAMP(6)),
('cif.processo.editar', 'Editar processos em fase permitida', NULL, 'processo', 1, UTC_TIMESTAMP(6)),
('cif.processo.aprovar', 'Aprovar processos de emissão', NULL, 'processo', 1, UTC_TIMESTAMP(6)),
('cif.processo.cancelar', 'Cancelar processos de emissão', NULL, 'processo', 1, UTC_TIMESTAMP(6)),
('cif.emissao.visualizar', 'Visualizar emissões', NULL, 'emissao', 1, UTC_TIMESTAMP(6)),
('cif.emissao.gerar', 'Gerar emissão CIF', NULL, 'emissao', 1, UTC_TIMESTAMP(6)),
('cif.emissao.ativar', 'Ativar emissão CIF', NULL, 'emissao', 1, UTC_TIMESTAMP(6)),
('cif.emissao.entregar', 'Registrar entrega de CIF', NULL, 'emissao', 1, UTC_TIMESTAMP(6)),
('cif.emissao.imprimir', 'Gerar/reimprimir artefato CIF', NULL, 'emissao', 1, UTC_TIMESTAMP(6)),
('cif.emissao.cancelar', 'Encerrar/cancelar emissão quando permitido', NULL, 'emissao', 1, UTC_TIMESTAMP(6)),
('cif.ocorrencia.visualizar', 'Visualizar ocorrências', NULL, 'ocorrencia', 1, UTC_TIMESTAMP(6)),
('cif.ocorrencia.registrar', 'Registrar ocorrências', NULL, 'ocorrencia', 1, UTC_TIMESTAMP(6)),
('cif.ocorrencia.recuperar', 'Registrar recuperação de documento', NULL, 'ocorrencia', 1, UTC_TIMESTAMP(6)),
('cif.recolhimento.visualizar', 'Visualizar recolhimentos', NULL, 'recolhimento', 1, UTC_TIMESTAMP(6)),
('cif.recolhimento.registrar', 'Registrar recolhimento', NULL, 'recolhimento', 1, UTC_TIMESTAMP(6)),
('cif.recolhimento.inutilizar', 'Registrar inutilização', NULL, 'recolhimento', 1, UTC_TIMESTAMP(6)),
('cif.relatorio.visualizar', 'Visualizar relatórios', NULL, 'relatorio', 1, UTC_TIMESTAMP(6)),
('cif.relatorio.exportar', 'Exportar relatórios', NULL, 'relatorio', 1, UTC_TIMESTAMP(6)),
('cif.auditoria.visualizar', 'Visualizar auditoria', NULL, 'auditoria', 1, UTC_TIMESTAMP(6)),
('cif.sincronizacao.visualizar', 'Visualizar sincronização', NULL, 'sincronizacao', 1, UTC_TIMESTAMP(6)),
('cif.sincronizacao.reenviar', 'Reenviar evento de sincronização', NULL, 'sincronizacao', 1, UTC_TIMESTAMP(6)),
('cif.sincronizacao.reconciliar', 'Executar reconciliação', NULL, 'sincronizacao', 1, UTC_TIMESTAMP(6)),
('cif.sincronizacao.reconstruir', 'Reconstruir projeção pública', NULL, 'sincronizacao', 1, UTC_TIMESTAMP(6)),
('cif.numeracao.visualizar', 'Visualizar numeração', NULL, 'numeracao', 1, UTC_TIMESTAMP(6)),
('cif.numeracao.gerenciar_ciclo', 'Gerenciar ciclo de emissão', NULL, 'numeracao', 1, UTC_TIMESTAMP(6)),
('cif.configuracao.visualizar', 'Visualizar configurações CIF', NULL, 'configuracao', 1, UTC_TIMESTAMP(6)),
('cif.configuracao.editar', 'Editar configurações CIF', NULL, 'configuracao', 1, UTC_TIMESTAMP(6)),
('cif.seguranca.visualizar_usuarios', 'Visualizar usuários CIF', NULL, 'seguranca', 1, UTC_TIMESTAMP(6)),
('cif.seguranca.gerenciar_usuarios', 'Gerenciar usuários CIF', NULL, 'seguranca', 1, UTC_TIMESTAMP(6)),
('cif.seguranca.gerenciar_perfis', 'Gerenciar perfis CIF', NULL, 'seguranca', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE nome = VALUES(nome), modulo = VALUES(modulo), ativo = VALUES(ativo)
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
CROSS JOIN permissoes perm
WHERE p.codigo = 'ADMIN_CIF' AND perm.ativo = 1
SQL,
        <<<'SQL'
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT p.id, perm.id
FROM perfis_acesso p
JOIN permissoes perm ON perm.codigo IN (
    'cif.agente.visualizar',
    'cif.agente.criar',
    'cif.agente.editar',
    'cif.biometria.visualizar',
    'cif.biometria.capturar',
    'cif.processo.visualizar',
    'cif.processo.criar',
    'cif.processo.editar',
    'cif.emissao.visualizar',
    'cif.ocorrencia.visualizar',
    'cif.recolhimento.visualizar'
)
WHERE p.codigo = 'EDITOR_CIF'
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
WHERE p.codigo = 'CONSULTA_CIF'
SQL,
    ],
];
