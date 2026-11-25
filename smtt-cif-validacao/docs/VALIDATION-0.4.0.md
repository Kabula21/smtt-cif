# Validação 0.4.0

Fluxo público esperado:

1. QR aponta para `/validar/{TOKEN}`.
2. A rota aceita somente token Base64URL de 43 caracteres (32 bytes / 256 bits).
3. O portal calcula `SHA-256(TOKEN)` e consulta `public_issuances.token_hash`.
4. Se houver correspondência, exibe diretamente a situação e os dados públicos permitidos.
5. Se não houver correspondência, exibe resposta genérica sem permitir pesquisa alternativa.
6. O token nunca é persistido em plaintext pelo plugin público.

A segurança não depende de esconder a rota `/validar/`; depende do token aleatório de alta entropia, ausência de endpoints enumeráveis, rate limiting para tentativas inválidas, `noindex`, `no-store` e política de logs do servidor.
