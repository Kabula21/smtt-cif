# Homologação final - SMTT CIF Gestão 1.0.0

## Objetivo

Executar uma validação ponta a ponta antes da entrada em produção e antes da ativação da sincronização com o plugin público.

## 1. Infraestrutura

- [ ] PHP 8.1 ou superior.
- [ ] Extensões `mysqli`, `json` e `sodium` habilitadas.
- [ ] Banco `smtt_cif` acessível pela aplicação.
- [ ] Diretório privado fora do webroot, com leitura e escrita pelo processo PHP.
- [ ] Backup do banco e do diretório privado definidos.
- [ ] HTTPS habilitado no ambiente de produção.

## 2. Instalação e migrations

- [ ] Atualizar/instalar o plugin.
- [ ] Confirmar que as migrations 001 a 024 estão aplicadas.
- [ ] Abrir `/cif/diagnostico` e confirmar ausência de erro bloqueante.
- [ ] Confirmar integridade da cadeia de auditoria.

## 3. Acesso e perfis

### ADMIN_CIF

- [ ] Visualiza todos os módulos.
- [ ] Pode configurar emissão, usuários e sincronização.
- [ ] Pode remover acesso CIF de outro usuário.
- [ ] Pode excluir agente somente quando não existem vínculos históricos.

### EDITOR_CIF

- [ ] Executa somente operações concedidas.
- [ ] Não acessa configurações perigosas.
- [ ] Não remove usuários.
- [ ] Não exclui agentes.

### CONSULTA_CIF

- [ ] Consulta registros autorizados.
- [ ] Não edita, exclui, emite ou tramita.

## 4. Agente

- [ ] Cadastrar novo agente.
- [ ] Validar matrícula, nome, CPF, nascimento, naturalidade, tipo sanguíneo, admissão e situação funcional.
- [ ] Editar o cadastro e confirmar criação de nova versão biográfica.
- [ ] Inativar e reativar.
- [ ] Testar exclusão de um agente sem vínculos, confirmando modal de segurança.
- [ ] Criar vínculo histórico e confirmar bloqueio da exclusão definitiva.

## 5. Biometria

- [ ] Capturar/enviar fotografia válida.
- [ ] Capturar/enviar assinatura válida.
- [ ] Confirmar armazenamento privado.
- [ ] Confirmar preview na ficha do agente.
- [ ] Invalidar uma coleta com justificativa e confirmar preservação histórica.

## 6. Processo de emissão

- [ ] Criar processo sem informar protocolo CIF.
- [ ] Confirmar geração automática `CIF-AAAA-000001` ou próximo sequencial.
- [ ] Criar segundo processo e confirmar incremento.
- [ ] Confirmar imutabilidade do protocolo CIF.
- [ ] Preencher protocolo externo opcional e confirmar exibição separada.
- [ ] Validar os dados biográficos pelo titular.
- [ ] Anexar documentos.
- [ ] Tramitar até aprovação.
- [ ] Testar bloqueios de requisitos obrigatórios.

## 7. Extravio / perda / furto / roubo

- [ ] Registrar ocorrência correspondente.
- [ ] Confirmar suspensão imediata da CIF quando aplicável.
- [ ] Confirmar BO quando obrigatório.
- [ ] Abrir processo a partir da ocorrência e confirmar reaproveitamento auditado dos documentos.
- [ ] Confirmar cálculo do prazo de 45 dias para nova emissão quando aplicável.
- [ ] Testar recuperação antes e depois de substituição.

## 8. Preparação de emissão

- [ ] Em instalação sem ciclo, confirmar criação automática do Ciclo 01.
- [ ] Confirmar signatário vigente.
- [ ] Confirmar URL base oficial de validação.
- [ ] Confirmar que abrir novo ciclo está apresentado como operação excepcional.
- [ ] Confirmar que novo ciclo exige justificativa e não reinicia o sequencial global.

## 9. Geração da CIF

- [ ] Gerar a emissão a partir de processo aprovado.
- [ ] Confirmar código `AJU##SEBR@@@@`.
- [ ] Confirmar token QR exclusivo.
- [ ] Confirmar status inicial `PENDENTE_ATIVACAO`.
- [ ] Confirmar preview do QR na tela da emissão.
- [ ] Confirmar frente e verso CR80.

## 10. Impressão

- [ ] Abrir arte de impressão.
- [ ] Confirmar dimensão 85,60 mm x 53,98 mm.
- [ ] Conferir foto, assinatura, dados e QR.
- [ ] Registrar tentativa concluída.
- [ ] Registrar tentativa com falha e confirmar nova tentativa no mesmo código quando permitido.
- [ ] Confirmar bloqueio de reprodução física indevida depois da entrega.

## 11. Entrega e ativação

- [ ] Registrar entrega.
- [ ] Confirmar CIF nova como `ATIVA / ENTREGUE`.
- [ ] Em substituição, confirmar encerramento/recolhimento da anterior quando aplicável.
- [ ] Confirmar somente uma CIF ativa por agente.

## 12. Cancelamento de emissão

- [ ] Criar uma emissão pendente.
- [ ] Cancelar administrativamente com motivo e justificativa.
- [ ] Confirmar `ENCERRADA` e preservação do código/QR/histórico.
- [ ] Confirmar inutilização física quando o cartão já estava em guarda.
- [ ] Confirmar que emissão ativa/encerrada não pode ser cancelada novamente de forma inválida.

## 13. Recolhimento e inutilização

- [ ] Criar recolhimento administrativo.
- [ ] Registrar entrega física do documento recolhido.
- [ ] Testar não recolhimento.
- [ ] Testar inutilização somente quando permitida.
- [ ] Confirmar histórico e auditoria.

## 14. Relatórios

- [ ] Consultar relatório de agentes.
- [ ] Consultar processos.
- [ ] Consultar emissões.
- [ ] Consultar ocorrências.
- [ ] Consultar recolhimentos.
- [ ] Aplicar filtros.
- [ ] Exportar CSV e abrir o arquivo gerado.

## 15. Auditoria

- [ ] Pesquisar por operação.
- [ ] Pesquisar por usuário.
- [ ] Pesquisar por entidade/UUID.
- [ ] Conferir antes/depois e justificativa quando disponíveis.
- [ ] Abrir Diagnóstico e confirmar integridade da cadeia SHA-256.

## 16. Usuários

- [ ] Adicionar vínculo CIF a usuário WordPress existente.
- [ ] Trocar perfil.
- [ ] Desativar e reativar.
- [ ] Remover acesso CIF e confirmar saída da lista operacional.
- [ ] Confirmar preservação histórica.
- [ ] Testar usuário cuja conta WordPress já tenha sido removida.

## 17. Sincronização - antes do plugin público

- [ ] Manter sincronização desabilitada.
- [ ] Não configurar worker de produção ainda.
- [ ] Não usar URL temporária de validação em emissão definitiva.

## 18. Sincronização - depois do plugin público

- [ ] Configurar URL HTTPS do portal.
- [ ] Gerar chave Ed25519 na intranet.
- [ ] Cadastrar somente a chave pública no portal.
- [ ] Ativar chave no Gestão.
- [ ] Executar heartbeat e exigir resposta `result=OK`.
- [ ] Sincronizar emissão.
- [ ] Validar `APPLIED`, `DUPLICATE` e `STALE_IGNORED`.
- [ ] Executar reconciliação.
- [ ] Confirmar que `REMOTE_AHEAD` gera alerta e nunca sobrescreve o banco interno.

## Critério de aceite

O Gestão pode ser considerado homologado quando todos os itens aplicáveis acima forem aprovados no ambiente real, sem erros bloqueantes no Diagnóstico e sem divergência na auditoria.
