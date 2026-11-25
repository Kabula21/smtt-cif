# Contrato real de integração — SMTT CIF Gestão → SMTT CIF Validação

Este documento registra o contrato encontrado no plugin de referência fornecido para o **SMTT CIF - Gestão**. O ZIP de referência está rotulado como versão estável 1.0.0 e sua própria documentação informa que ele congela funcionalmente a 0.19.0. Nenhum arquivo do Gestão foi alterado durante este desenvolvimento.

## Direção de comunicação

A comunicação é unidirecional: **intranet → portal público**. O portal público nunca consulta o banco `smtt_cif` e possui banco próprio `smtt_cif_public`.

## Endpoints efetivamente esperados pelo Gestão

Todos são chamados com `POST`:

- `/wp-json/smtt-cif/v1/sync/events`
- `/wp-json/smtt-cif/v1/sync/heartbeat`
- `/wp-json/smtt-cif/v1/sync/reconcile`

## Protocolo e assinatura

Versão do protocolo: `1`.

Headers assinados/enviados:

- `X-SMTT-CIF-Protocol`
- `X-SMTT-CIF-Environment`
- `X-SMTT-CIF-Key-Id`
- `X-SMTT-CIF-Timestamp`
- `X-SMTT-CIF-Request-Id`
- `X-SMTT-CIF-Content-SHA256`
- `X-SMTT-CIF-Signature`

`X-SMTT-CIF-Content-SHA256` é SHA-256 do corpo bruto, codificado em Base64URL sem padding.

A string canônica é:

```text
SMTT-CIF-SIGNATURE-V1
POST
{PATH_DO_ENDPOINT}
1
{environment}
{key_id}
{timestamp}
{request_id}
{content_sha256_base64url}
```

A assinatura é Ed25519 detached e também é transportada em Base64URL sem padding.

O Gestão mantém a chave privada. O Validação armazena somente a chave pública Ed25519 correspondente.

### Modo dual do Validação 0.2.0

O painel do plugin expõe uma chave operacional com dois estados:

- **LOCAL / HOMOLOGAÇÃO** → o receptor aceita somente `X-SMTT-CIF-Environment: homologation`;
- **PRODUÇÃO** → o receptor aceita somente `X-SMTT-CIF-Environment: production`.

A mudança para Produção exige HTTPS configurado no WordPress. Key IDs gerados pelo Gestão com prefixos `smtt-cif-hml-` e `smtt-cif-prod-` são conferidos contra o modo atual para reduzir mistura acidental de ambientes. O protocolo assinado permanece inalterado.

## Evento de projeção

O evento da outbox interna é materializado no fio como:

```json
{
  "protocol_version": 1,
  "event_id": "uuid",
  "event_type": "CIF_PROJECTION_BATCH",
  "event_created_at": "YYYY-MM-DDTHH:MM:SSZ",
  "payload_version": 1,
  "items": [
    {
      "emission_id": "uuid",
      "record_version": 1,
      "token": "Base64URL de 32 bytes",
      "code": "AJU01SEBR0001",
      "name": "Nome do agente",
      "registration": "matrícula",
      "admission_date": "YYYY-MM-DD ou null",
      "issued_at": "YYYY-MM-DDTHH:MM:SSZ",
      "public_status": "VALIDA",
      "source_updated_at": "YYYY-MM-DDTHH:MM:SSZ",
      "projection_hash": "sha256 hexadecimal"
    }
  ]
}
```

O contrato atual do Gestão transmite somente os estados públicos `VALIDA` e `NAO_VALIDA`. Portanto, a versão 0.2.0 do Validação não inventa estados adicionais no protocolo.

O `token` existe em plaintext somente durante a requisição recebida. O Validação persiste apenas `SHA-256(token)` em binário.

O `projection_hash` é assinado pelo Gestão e é persistido como hash de reconciliação. A serialização atual do Gestão calcula esse hash usando a data original do banco, enquanto o payload transmite `issued_at` em ISO sem microssegundos; por isso o portal não tenta recalcular esse hash a partir do payload recebido.

## ACK de evento

HTTP 200 com:

```json
{
  "event_id": "uuid",
  "result": "APPLIED",
  "plugin_version": "0.2.0",
  "schema_version": "20260921_002_runtime_mode"
}
```

Resultados aceitos pelo Gestão:

- `APPLIED`
- `DUPLICATE`
- `STALE_IGNORED`

Regras implementadas:

- mesmo `event_id` e mesmo corpo: `DUPLICATE`;
- mesmo `event_id` com corpo diferente: HTTP 409;
- versão recebida menor que a armazenada: ignorada;
- mesma versão + mesmo `projection_hash`: no-op;
- mesma versão + hash diferente: HTTP 409;
- versão maior: aplicada em transação.

## Heartbeat

Payload esperado:

```json
{
  "protocol_version": 1,
  "event_type": "HEARTBEAT",
  "source_time": "YYYY-MM-DDTHH:MM:SSZ",
  "plugin_version": "1.0.0",
  "pending_events": 0,
  "attention_required": 0,
  "oldest_pending_age_seconds": null
}
```

Resposta:

```json
{
  "result": "OK",
  "plugin_version": "0.2.0",
  "protocol_version": 1,
  "environment": "homologation",
  "server_time": "YYYY-MM-DDTHH:MM:SSZ",
  "schema_version": "20260921_002_runtime_mode"
}
```

O Gestão exige `result = OK`; HTTP 200 isolado não basta.

## Reconciliação

O Gestão envia itens com:

```json
{
  "emission_id": "uuid",
  "desired_version": 10,
  "desired_hash": "sha256 hexadecimal"
}
```

O portal responde, para cada emissão, um destes estados:

- `MATCH`
- `MISSING`
- `VERSION_MISMATCH`
- `HASH_MISMATCH`
- `REMOTE_AHEAD`

`REMOTE_AHEAD` nunca provoca escrita no banco interno. O Gestão permanece como única fonte oficial.

## Proteções implementadas no receptor

- validação Ed25519;
- verificação do hash do corpo bruto;
- ambiente e protocolo obrigatórios;
- `key_id` precisa estar aceito;
- janela temporal configurável de 60 a 900 segundos, padrão 300;
- `request_id` anti-replay persistente;
- schema JSON estrito;
- rejeição de campos inesperados;
- transação para aplicação do evento;
- idempotência por `event_id`;
- monotonicidade de `record_version`;
- nenhum token completo nos logs técnicos do plugin.


## Consulta pública direta — Validação 0.4.0

O contrato de sincronização permanece inalterado. O campo `code` continua sendo projetado para identificação e exibição da CIF, mas deixa de funcionar como segundo fator da consulta pública.

O QR aponta para `/validar/{TOKEN}`. O `TOKEN` é Base64URL sem padding de 32 bytes aleatórios (256 bits; 43 caracteres). O portal calcula `SHA-256(token)` e faz lookup exclusivamente pela coluna `token_hash`, que possui índice `UNIQUE`. Não existe endpoint público de pesquisa por código, nome, CPF ou matrícula.

Consultas válidas não consomem o rate limit. Tentativas de token inválido são limitadas por origem. A aplicação envia `noindex`, `no-store`, `Referrer-Policy: no-referrer` e CSP restritiva. Como o token está no caminho da URL, a política de access logs do servidor HTTP/reverse proxy deve ser configurada para redigir ou evitar retenção integral de `/validar/{TOKEN}`.
