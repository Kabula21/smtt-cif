# SMTT CIF - Gestão 1.6.0

## Processo de emissão simplificado

- novos processos entram diretamente em `EM_ANALISE`, exibido na interface como **Em tramitação**;
- a etapa manual de "Formalizar e enviar para análise" deixa de existir para novos processos;
- perda, furto e roubo não recebem mais carência automática de 45 dias;
- datas de elegibilidade antigas são neutralizadas pela migration `20260924_028_simplified_process_flow`;
- quando a versão biográfica ainda não foi conferida, o Administrador CIF pode registrar a conferência no mesmo formulário de **Aprovar e liberar emissão**;
- a validação/conferência separada permanece disponível para perfis autorizados sem poder de aprovação, preservando segregação de funções;
- pendência documental, indeferimento, cancelamento, execução e conclusão permanecem disponíveis;
- não há alteração na numeração de ciclo por agente nem no sequencial global das CIFs.
