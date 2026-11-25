<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Security;

use RuntimeException;

final class DataProtection
{
    private const VERSION = "\x01";

    public function __construct(private readonly SecretKeyStore $keys)
    {
    }

    /** @return array{lookup:string,ciphertext:string,key_id:string} */
    public function protectCpf(string $cpfDigits): array
    {
        $this->assertSodium();
        $keyId = $this->keys->activeKeyId();
        $master = $this->keys->key($keyId);

        $lookupKey = $this->keys->lookupKey();
        $encKey = hash_hmac('sha256', 'SMTT-CIF:CPF:ENC:V1', $master, true);
        $lookup = hash_hmac('sha256', $cpfDigits, $lookupKey, true);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($cpfDigits, $nonce, $encKey);

        sodium_memzero($lookupKey);
        sodium_memzero($encKey);
        sodium_memzero($master);

        return [
            'lookup' => $lookup,
            'ciphertext' => self::VERSION . $nonce . $cipher,
            'key_id' => $keyId,
        ];
    }

    public function lookupCpf(string $cpfDigits): string
    {
        $lookupKey = $this->keys->lookupKey();
        $lookup = hash_hmac('sha256', $cpfDigits, $lookupKey, true);
        if (function_exists('sodium_memzero')) {
            sodium_memzero($lookupKey);
        }
        return $lookup;
    }

    public function decryptCpf(string $ciphertext, string $keyId): string
    {
        $this->assertSodium();
        $min = 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if (strlen($ciphertext) < $min || $ciphertext[0] !== self::VERSION) {
            throw new RuntimeException('O CPF protegido está em formato desconhecido.');
        }

        $master = $this->keys->key($keyId);
        $encKey = hash_hmac('sha256', 'SMTT-CIF:CPF:ENC:V1', $master, true);
        $nonce = substr($ciphertext, 1, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = substr($ciphertext, 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($payload, $nonce, $encKey);

        sodium_memzero($encKey);
        sodium_memzero($master);

        if (!is_string($plain)) {
            throw new RuntimeException('Não foi possível descriptografar o CPF armazenado.');
        }

        return $plain;
    }

    /** @return array{ciphertext:string,key_id:string} */
    public function protectQrToken(string $token): array
    {
        $this->assertSodium();
        $keyId = $this->keys->activeKeyId();
        $master = $this->keys->key($keyId);
        $encKey = hash_hmac('sha256', 'SMTT-CIF:QR-TOKEN:ENC:V1', $master, true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($token, $nonce, $encKey);
        sodium_memzero($encKey);
        sodium_memzero($master);
        return [
            'ciphertext' => self::VERSION . $nonce . $cipher,
            'key_id' => $keyId,
        ];
    }

    public function decryptQrToken(string $ciphertext, string $keyId): string
    {
        $this->assertSodium();
        $min = 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if (strlen($ciphertext) < $min || $ciphertext[0] !== self::VERSION) {
            throw new RuntimeException('O token QR protegido está em formato desconhecido.');
        }
        $master = $this->keys->key($keyId);
        $encKey = hash_hmac('sha256', 'SMTT-CIF:QR-TOKEN:ENC:V1', $master, true);
        $nonce = substr($ciphertext, 1, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = substr($ciphertext, 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($payload, $nonce, $encKey);
        sodium_memzero($encKey);
        sodium_memzero($master);
        if (!is_string($plain)) {
            throw new RuntimeException('Não foi possível descriptografar o token QR armazenado.');
        }
        return $plain;
    }

    private function assertSodium(): void
    {
        if (!extension_loaded('sodium') || !function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('A extensão PHP sodium é necessária para proteger os dados pessoais dos agentes.');
        }
    }
}
