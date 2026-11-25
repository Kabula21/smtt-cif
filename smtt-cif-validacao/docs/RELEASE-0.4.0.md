# SMTT CIF - Validação 0.4.0

## Alterações

- consulta pública direta pelo QR/token exclusivo da emissão;
- remoção do formulário de código `AJU##SEBR@@@@`;
- lookup público passa a usar somente `token_hash` (SHA-256), mantendo o token em plaintext apenas durante a requisição;
- rota pública restringida ao formato exato do token de 256 bits em Base64URL (43 caracteres);
- consultas válidas não consomem rate limit; tentativas inválidas continuam limitadas por origem;
- erros públicos usam mensagem genérica e não expõem existência de emissão, código ou dados internos;
- headers reforçados: `no-store`, `noindex`, `Referrer-Policy: no-referrer`, CSP, `Cross-Origin-Resource-Policy` e `Permissions-Policy`;
- nenhuma migration de banco necessária.

## Compatibilidade

O formato do QR já emitido permanece `/validar/{TOKEN}`. Portanto, cartões existentes continuam compatíveis; a diferença é que a página agora mostra diretamente a situação da emissão após a leitura do QR.
