<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Sync;

use RuntimeException;
use SMTT\CIF\Gestao\Support\Base64Url;

final class MessageSigner
{
    public const PROTOCOL_VERSION = 1;

    public function __construct(private readonly SyncSigningKeyStore $keys)
    {
    }

    /** @return array<string,string> */
    public function headers(string $method, string $url, string $body, string $environment, string $requestId, string $timestamp): array
    {
        $keyId = $this->keys->activeKeyId();
        if ($keyId === null || $keyId === '') {
            throw new RuntimeException('Nenhuma chave Ed25519 ativa foi configurada para sincronização.');
        }
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }
        $contentHashRaw = hash('sha256', $body, true);
        $contentHash = Base64Url::encode($contentHashRaw);
        $canonical = implode("\n", [
            'SMTT-CIF-SIGNATURE-V1',
            strtoupper($method),
            $path,
            (string) self::PROTOCOL_VERSION,
            $environment,
            $keyId,
            $timestamp,
            $requestId,
            $contentHash,
        ]);
        $secret = $this->keys->secret($keyId);
        $signature = sodium_crypto_sign_detached($canonical, $secret);
        sodium_memzero($secret);

        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
            'X-SMTT-CIF-Protocol' => (string) self::PROTOCOL_VERSION,
            'X-SMTT-CIF-Environment' => $environment,
            'X-SMTT-CIF-Key-Id' => $keyId,
            'X-SMTT-CIF-Timestamp' => $timestamp,
            'X-SMTT-CIF-Request-Id' => $requestId,
            'X-SMTT-CIF-Content-SHA256' => $contentHash,
            'X-SMTT-CIF-Signature' => Base64Url::encode($signature),
        ];
    }
}
