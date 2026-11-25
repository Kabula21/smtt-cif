<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Security;

final class CanonicalSignature
{
    public const PROTOCOL_VERSION = 1;

    public static function contentHash(string $body): string
    {
        return Base64Url::encode(hash('sha256', $body, true));
    }

    public static function build(
        string $method,
        string $path,
        string $environment,
        string $keyId,
        string $timestamp,
        string $requestId,
        string $contentHash
    ): string {
        return implode("\n", [
            'SMTT-CIF-SIGNATURE-V1',
            strtoupper($method),
            $path,
            (string)self::PROTOCOL_VERSION,
            $environment,
            $keyId,
            $timestamp,
            $requestId,
            $contentHash,
        ]);
    }

    private function __construct()
    {
    }
}
