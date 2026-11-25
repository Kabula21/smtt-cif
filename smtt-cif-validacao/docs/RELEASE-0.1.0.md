# Release 0.1.0

Primeira versão funcional do **SMTT CIF - Validação**.

Principais entregas:

- compatibilidade com o protocolo v1 real do Gestão;
- receptor Ed25519 com hash de conteúdo, timestamp, ambiente, `key_id` e anti-replay;
- projeção mínima em `smtt_cif_public`;
- token persistido somente como SHA-256;
- idempotência e controle de versão;
- heartbeat e reconciliação;
- página pública `/validar/{TOKEN}` com segundo fator de consulta pelo código CIF;
- rate limiting;
- headers de privacidade e segurança;
- painel técnico `manage_options` sem edição das projeções;
- documentação de implantação e contrato.

O plugin **SMTT CIF - Gestão** de referência não foi alterado.
