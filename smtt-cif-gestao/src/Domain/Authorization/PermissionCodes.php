<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\Authorization;

final class PermissionCodes
{
    public const AGENTE_VISUALIZAR = 'cif.agente.visualizar';
    public const AGENTE_CRIAR = 'cif.agente.criar';
    public const AGENTE_EDITAR = 'cif.agente.editar';
    public const AGENTE_VISUALIZAR_DADOS_SENSIVEIS = 'cif.agente.visualizar_dados_sensiveis';
    public const AGENTE_IMPORTAR = 'cif.agente.importar';
    public const AGENTE_EXPORTAR = 'cif.agente.exportar';
    public const AGENTE_EXCLUIR = 'cif.agente.excluir';

    public const BIOMETRIA_VISUALIZAR = 'cif.biometria.visualizar';
    public const BIOMETRIA_CAPTURAR = 'cif.biometria.capturar';
    public const BIOMETRIA_INVALIDAR = 'cif.biometria.invalidar';

    public const PROCESSO_VISUALIZAR = 'cif.processo.visualizar';
    public const PROCESSO_CRIAR = 'cif.processo.criar';
    public const PROCESSO_EDITAR = 'cif.processo.editar';
    public const PROCESSO_APROVAR = 'cif.processo.aprovar';
    public const PROCESSO_VALIDAR_DADOS = 'cif.processo.validar_dados';
    public const PROCESSO_ANEXAR_DOCUMENTOS = 'cif.processo.anexar_documentos';
    public const PROCESSO_CANCELAR = 'cif.processo.cancelar';

    public const EMISSAO_VISUALIZAR = 'cif.emissao.visualizar';
    public const EMISSAO_GERAR = 'cif.emissao.gerar';
    public const EMISSAO_ATIVAR = 'cif.emissao.ativar';
    public const EMISSAO_ENTREGAR = 'cif.emissao.entregar';
    public const EMISSAO_IMPRIMIR = 'cif.emissao.imprimir';
    public const EMISSAO_CANCELAR = 'cif.emissao.cancelar';
    public const EMISSAO_VISUALIZAR_TOKEN_QR = 'cif.emissao.visualizar_token_qr';

    public const OCORRENCIA_VISUALIZAR = 'cif.ocorrencia.visualizar';
    public const OCORRENCIA_REGISTRAR = 'cif.ocorrencia.registrar';
    public const OCORRENCIA_RECUPERAR = 'cif.ocorrencia.recuperar';

    public const RECOLHIMENTO_VISUALIZAR = 'cif.recolhimento.visualizar';
    public const RECOLHIMENTO_REGISTRAR = 'cif.recolhimento.registrar';
    public const RECOLHIMENTO_INUTILIZAR = 'cif.recolhimento.inutilizar';

    public const RELATORIO_VISUALIZAR = 'cif.relatorio.visualizar';
    public const RELATORIO_EXPORTAR = 'cif.relatorio.exportar';

    public const AUDITORIA_VISUALIZAR = 'cif.auditoria.visualizar';

    public const SINCRONIZACAO_VISUALIZAR = 'cif.sincronizacao.visualizar';
    public const SINCRONIZACAO_REENVIAR = 'cif.sincronizacao.reenviar';
    public const SINCRONIZACAO_RECONCILIAR = 'cif.sincronizacao.reconciliar';
    public const SINCRONIZACAO_RECONSTRUIR = 'cif.sincronizacao.reconstruir';
    public const SINCRONIZACAO_CONFIGURAR = 'cif.sincronizacao.configurar';

    public const NUMERACAO_VISUALIZAR = 'cif.numeracao.visualizar';
    public const NUMERACAO_GERENCIAR_CICLO = 'cif.numeracao.gerenciar_ciclo';

    public const SIGNATARIO_VISUALIZAR = 'cif.signatario.visualizar';
    public const SIGNATARIO_GERENCIAR = 'cif.signatario.gerenciar';

    public const CONFIGURACAO_VISUALIZAR = 'cif.configuracao.visualizar';
    public const CONFIGURACAO_EDITAR = 'cif.configuracao.editar';

    public const SEGURANCA_VISUALIZAR_USUARIOS = 'cif.seguranca.visualizar_usuarios';
    public const SEGURANCA_GERENCIAR_USUARIOS = 'cif.seguranca.gerenciar_usuarios';
    public const SEGURANCA_GERENCIAR_PERFIS = 'cif.seguranca.gerenciar_perfis';
    public const SEGURANCA_REMOVER_USUARIOS = 'cif.seguranca.remover_usuarios';

    private function __construct()
    {
    }
}
