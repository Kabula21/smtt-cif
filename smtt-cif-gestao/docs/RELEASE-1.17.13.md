# SMTT CIF – Gestão 1.17.13 – Termo assinado na entrega

## Escopo

Atualização **somente do plugin interno** `smtt-cif-gestao`. Não altera o plugin de validação no portal público nem o contrato de sincronização existente.

- Mantém os ajustes de apresentação, relatórios, caixa alta e termo impresso das versões 1.17.10–1.17.12.
- Termo A4 preenchido automaticamente: nome, CPF, matrícula, código, tipo e data da emissão, com marca SMTT e assinaturas do agente e responsável RH-SMTT.
- Na emissão física produzida, o RH anexa a digitalização do termo assinado (PDF, JPG ou PNG, até 10 MB) e confirma a conferência das **duas assinaturas**.
- O arquivo é validado pelo MIME e assinatura do conteúdo, criptografado no armazenamento privado da intranet, vinculado à emissão e registrado em auditoria.
- É permitido anexar uma digitalização corrigida **antes da entrega**. Os arquivos anteriores ficam preservados no histórico (não sobrescritos).
- Exibição do comprovante com download autenticado para perfis com permissão de entrega **e** ativação. Nenhum documento é publicado no portal externo.
- A entrega e a ativação são **bloqueadas no servidor**, dentro da mesma transação, sem um termo conferido e disponível no armazenamento privado; apenas o termo assinado é aceito como método de confirmação.
- Uma única migration aditiva `20261013_031_signed_delivery_term` cria `emissoes_termos_entrega`, com referências a `emissoes_cif`, `arquivos_privados` e `usuarios_cif`. Nenhuma tabela existente, chave, senha ou configuração é eliminada.

## Homologação sugerida antes de produção

1. Realizar cópia de segurança dos bancos e arquivos privados da intranet.
2. Atualizar o ZIP preservando todas as configurações externas e o diretório privado `smtt-cif-private`.
3. Abrir `/cif` para aplicar a migration pendente; confirmar sua aplicação em Diagnóstico ou WP-CLI `wp smtt-cif migration-status`.
4. Em uma emissão pendente com produção concluída, imprimir o termo em outra aba; colher ambas assinaturas; digitalizar.
5. Conferir que o botão de ativação está bloqueado sem anexo. Tentar requisição direta sem termo e confirmar que a ativação é rejeitada.
6. Anexar PDF/JPG/PNG válido com ambas assinaturas conferidas; visualizar o registro e baixar o arquivo.
7. Confirmar a entrega e ativação. Verificar a auditoria e o estado público; **nenhum arquivo assinado deve ser transmitido ao portal**.
8. Verificar segunda via/substituição e que uma emissão já entregue não aceita substituir comprovantes.
9. Testar arquivo inválido e arquivo acima de 10 MB; confirmar que nada é ativado.

### Importante

- O bloqueio vale para **novas ativações**. Emissões já entregues antes da atualização não são desativadas retroativamente.
- PDFs com scripts são disponibilizados como **download**, não abertos automaticamente dentro do aplicativo.
- Na futura limpeza definitiva para produção, incluir a nova tabela `emissoes_termos_entrega` e os arquivos privados da categoria `termo_entrega_assinado`, obedecendo dependências e preservando as chaves institucionais.
- Testes de sintaxe e verificações de regressão no pacote não substituem ensaio de ponta a ponta no XAMPP com banco real.
