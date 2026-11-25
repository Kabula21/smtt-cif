<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Security;

final class CifUser
{
    /**
     * @param list<string> $profiles
     * @param list<string> $permissions
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly ?int $wpUserId,
        public readonly string $login,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $department,
        public readonly bool $active,
        public readonly bool $mustChangePassword,
        public readonly ?string $lastLoginAt,
        public readonly array $profiles,
        public readonly array $permissions
    ) {
    }

    public function hasProfile(string $code): bool
    {
        return in_array($code, $this->profiles, true);
    }

    public function isPluginAdministrator(): bool
    {
        return $this->hasProfile('ADMIN_CIF');
    }

    public function isSystemAdministrator(): bool
    {
        return $this->isPluginAdministrator();
    }

    public function can(string $permission): bool
    {
        if ($this->isPluginAdministrator()) {
            return true;
        }

        return in_array($permission, $this->permissions, true);
    }
}
