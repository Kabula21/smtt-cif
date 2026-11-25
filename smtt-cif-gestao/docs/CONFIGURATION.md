# Configuração do SMTT CIF - Gestão

## Banco oficial

O banco padrão é `smtt_cif` e permanece separado do banco do WordPress.

Constantes opcionais:

```php
define('SMTT_CIF_DB_HOST', '127.0.0.1');
define('SMTT_CIF_DB_PORT', '3306');
define('SMTT_CIF_DB_NAME', 'smtt_cif');
define('SMTT_CIF_DB_USER', 'usuario');
define('SMTT_CIF_DB_PASSWORD', 'senha');
define('SMTT_CIF_DB_CHARSET', 'utf8mb4');
```

## Diretório de segredos

A partir da versão 0.3.0 o plugin protege CPF com chave fora do banco.

O caminho padrão é um diretório `smtt-cif-private` no diretório pai da instalação WordPress. Em produção recomenda-se definir explicitamente:

```php
define('SMTT_CIF_SECRET_DIR', '/var/lib/smtt-cif/secrets');
```

No Windows:

```php
define('SMTT_CIF_SECRET_DIR', 'D:/smtt-cif-private/secrets');
```

O processo PHP precisa ter leitura/escrita nesse diretório. O keyring criado ali deve ser incluído em backup criptografado e não deve ser servido pelo servidor web.

## Storage privado de biometria

A partir da versão 0.4.0, fotografia e assinatura são armazenadas criptografadas fora da Media Library do WordPress.

Por padrão, o diretório é:

```text
<diretório pai do WordPress>/smtt-cif-private/storage
```

O keyring utilizado para derivar as chaves de criptografia continua em:

```text
<diretório pai do WordPress>/smtt-cif-private/data-keys.json
```

O diretório privado deve permanecer fora do webroot, gravável somente pelo processo PHP e incluído no backup protegido do sistema. Não exponha `storage/` por alias do servidor web.

## Sincronização com o portal público (0.10.0+)

A configuração funcional deve ser feita em `/cif/sincronizacao`. A chave privada Ed25519 fica no diretório retornado por `SMTT_CIF_SECRET_DIR` (ou no diretório privado padrão do plugin) e nunca deve ser enviada ao portal.

Produção exige URL HTTPS. Em homologação, HTTP é aceito somente para host local/loopback (`localhost`, `127.0.0.1`, `::1`, `.local` ou `.test`).

O worker recomendado em produção é o WP-CLI executado por cron do sistema operacional:

```bash
wp smtt-cif sync run --limit=50
```

A sincronização deve permanecer desabilitada enquanto o plugin público ainda não estiver instalado ou enquanto a chave pública correspondente não estiver cadastrada nele.
