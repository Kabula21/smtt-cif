<?php

declare(strict_types=1);

/**
 * Catálogo documental das rotas do Sistema CIF.
 *
 * O despacho HTTP é realizado por Web\Router. Este arquivo existe para manter
 * uma referência única e legível das URLs funcionais da versão estável.
 */
return [
    'login' => '/cif/login',
    'logout' => '/cif/logout',
    'account.password' => '/cif/minha-senha',
    'dashboard' => '/cif',

    'agents.index' => '/cif/agentes',
    'agents.create' => '/cif/agentes/novo',
    'agents.show' => '/cif/agentes/{uuid}',
    'agents.edit' => '/cif/agentes/{uuid}/editar',
    'agents.status' => '/cif/agentes/{uuid}/status',
    'agents.delete' => '/cif/agentes/{uuid}/excluir',
    'agents.export' => '/cif/agentes/exportar',
    'agents.import' => '/cif/agentes/importar',
    'agents.import_batch' => '/cif/agentes/importacoes/{uuid}',

    'biometry.index' => '/cif/biometria',
    'biometry.manage' => '/cif/agentes/{uuid}/biometria',
    'biometry.invalidate' => '/cif/agentes/{uuid}/biometria/{collection_uuid}/invalidar',
    'biometry.file' => '/cif/biometria/arquivo/{uuid}',

    'processes.index' => '/cif/processos',
    'processes.create' => '/cif/processos/novo',
    'processes.show' => '/cif/processos/{uuid}',
    'processes.edit' => '/cif/processos/{uuid}/editar',
    'processes.transition' => '/cif/processos/{uuid}/transicao',
    'processes.validate_biography' => '/cif/processos/{uuid}/validar-dados',
    'processes.documents' => '/cif/processos/{uuid}/documentos',
    'processes.file' => '/cif/processos/arquivo/{uuid}',

    'emissions.index' => '/cif/emissoes',
    'emissions.setup' => '/cif/emissoes/preparacao',
    'emissions.signer' => '/cif/emissoes/preparacao/signatario',
    'emissions.validation_url' => '/cif/emissoes/preparacao/url-validacao',
    'emissions.generate' => '/cif/processos/{uuid}/gerar-emissao',
    'emissions.show' => '/cif/emissoes/{uuid}',
    'emissions.cancel' => '/cif/emissoes/{uuid}/cancelar',
    'emissions.artifact' => '/cif/emissoes/{uuid}/artefato',
    'emissions.preview' => '/cif/emissoes/{uuid}/visualizar',
    'emissions.print' => '/cif/emissoes/{uuid}/imprimir',
    'emissions.print_view' => '/cif/emissoes/{uuid}/impressao/{id}',
    'emissions.print_result' => '/cif/emissoes/{uuid}/impressao/{id}/resultado',
    'emissions.delivery' => '/cif/emissoes/{uuid}/entregar',
    'emissions.receipt_term' => '/cif/emissoes/{uuid}/termo-entrega',
    'emissions.signed_term_attach' => '/cif/emissoes/{uuid}/termo-assinado/anexar',
    'emissions.signed_term_file' => '/cif/emissoes/{uuid}/termo-assinado/{file_uuid}',

    'occurrences.index' => '/cif/ocorrencias',
    'occurrences.create' => '/cif/emissoes/{uuid}/ocorrencias/nova',
    'occurrences.show' => '/cif/ocorrencias/{uuid}',
    'occurrences.recover' => '/cif/ocorrencias/{uuid}/recuperar',
    'occurrences.file' => '/cif/ocorrencias/arquivo/{uuid}',

    'recollections.index' => '/cif/recolhimentos',
    'recollections.create' => '/cif/emissoes/{uuid}/recolhimentos/novo',
    'recollections.show' => '/cif/recolhimentos/{uuid}',
    'recollections.collect' => '/cif/recolhimentos/{uuid}/recolher',
    'recollections.not_collected' => '/cif/recolhimentos/{uuid}/nao-recolhido',
    'recollections.invalidate' => '/cif/recolhimentos/{uuid}/inutilizar',

    'reports.index' => '/cif/relatorios',
    'reports.export' => '/cif/relatorios/exportar',
    'audit.index' => '/cif/auditoria',
    'diagnostics.index' => '/cif/diagnostico',

    'security.users' => '/cif/seguranca/usuarios',
    'security.users_remove' => '/cif/seguranca/usuarios/{uuid}/remover',

    'sync.index' => '/cif/sincronizacao',
    'sync.config' => '/cif/sincronizacao/configurar',
    'sync.key_generate' => '/cif/sincronizacao/chaves/gerar',
    'sync.key_activate' => '/cif/sincronizacao/chaves/ativar',
    'sync.run' => '/cif/sincronizacao/executar',
    'sync.heartbeat' => '/cif/sincronizacao/heartbeat',
    'sync.reconcile' => '/cif/sincronizacao/reconciliar',
    'sync.rebuild' => '/cif/sincronizacao/reconstruir',
    'sync.requeue' => '/cif/sincronizacao/eventos/{uuid}/reenviar',
];
