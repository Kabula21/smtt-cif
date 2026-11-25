<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Security;

use InvalidArgumentException;

final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new InvalidArgumentException('Base64URL inválido.');
        }
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new InvalidArgumentException('Base64URL inválido.');
        }
        return $decoded;
    }

    private function __construct()
    {
    }
}
