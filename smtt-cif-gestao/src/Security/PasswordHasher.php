<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Security;

use RuntimeException;

final class PasswordHasher
{
    public function hash(string $password): string
    {
        $this->assertPolicy($password);
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Não foi possível proteger a senha do usuário.');
        }
        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_needs_rehash($hash, $algorithm);
    }

    public function assertPolicy(string $password): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        if ($length < 10) {
            throw new RuntimeException('A senha deve possuir pelo menos 10 caracteres.');
        }
        if ($length > 255) {
            throw new RuntimeException('A senha informada é muito longa.');
        }
    }
}
