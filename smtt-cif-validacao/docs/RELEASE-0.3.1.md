# Release 0.3.1

Patch de experiência da página pública.

- Após uma validação que localiza a CIF, o formulário de código não é repetido.
- O estado de resultado exibe um único botão **Voltar**, que retorna ao GET da mesma rota `/validar/{token}` e restaura a tela inicial.
- Em erro de código/QR, o formulário continua disponível para correção do código.
- Sem mudanças em API, banco, migrations, assinatura Ed25519, replay guard ou sincronização.
