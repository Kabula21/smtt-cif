# Roteiro técnico de homologação - SMTT CIF Gestão 0.17.0

Este arquivo acompanha o plugin como checklist resumido. O manual operacional completo é entregue separadamente em PDF.

## Pré-condições

- Backup do WordPress e do banco `smtt_cif`.
- PHP 8.1+ com mysqli, JSON e sodium.
- Diretório privado fora da área pública do WordPress e gravável pelo PHP.
- Usuário administrador com vínculo CIF ativo.
- Sincronização pública desabilitada até o plugin público ser instalado.

## Sequência mínima

1. Atualizar o plugin e visitar o `wp-admin` para aplicar migrations pendentes.
2. Abrir `/cif/diagnostico` e conferir migrations, banco, diretório privado e auditoria.
3. Conferir usuários/perfis e matriz de permissões.
4. Em Configurações, confirmar a criação automática do Ciclo 01 quando ainda não houver ciclo; cadastrar signatário e informar URL de validação de homologação.
5. Cadastrar agente de teste.
6. Cadastrar fotografia e assinatura biométrica válidas.
7. Abrir processo de primeira emissão e conferir o protocolo CIF automático `CIF-AAAA-######`; preencher referência externa somente se houver.
8. Registrar validação dos dados pelo titular.
9. Anexar documentos exigidos e tramitar até aprovação.
10. Gerar emissão e conferir código `AJU##SEBR@@@@` e QR.
11. Gerar arte, abrir impressão e registrar o resultado.
12. Entregar/ativar e conferir estados `ATIVA` + `ENTREGUE`.
13. Criar cenários independentes para perda/furto/roubo, recuperação, recolhimento, substituição e cancelamento administrativo de emissão ainda pendente.
14. Conferir Relatórios e Auditoria.
15. Reexecutar Diagnóstico.

## Critérios de aprovação

- Nenhuma migration pendente ou com checksum divergente.
- Nenhum bloqueio técnico no Diagnóstico.
- Código CIF único e no padrão previsto.
- QR/token não exposto em listagens públicas ou URLs administrativas desnecessárias.
- Emissão histórica nunca é apagada.
- Operações críticas aparecem em Auditoria.
- Perfil Consulta não consegue editar, excluir, emitir ou administrar.
- Perfil Editor não acessa configurações administrativas críticas.
- Administrador consegue executar as rotinas administrativas previstas.
- Sincronização continua desabilitada até o plugin público estar pronto.
