<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Service;

use InvalidArgumentException;
use SMTT\CIF\Validacao\Repository\SettingsRepository;

final class RuntimeModeService
{
    public const MODE_LOCAL = 'local';
    public const MODE_PRODUCTION = 'production';

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function mode(): string
    {
        $mode = strtolower((string)$this->settings->get('runtime.mode', ''));
        if (in_array($mode, [self::MODE_LOCAL, self::MODE_PRODUCTION], true)) {
            return $mode;
        }

        // Compatibilidade transparente com instalações 0.1.0.
        $legacyEnvironment = strtolower((string)$this->settings->get('sync.environment', 'homologation'));
        return $legacyEnvironment === 'production' ? self::MODE_PRODUCTION : self::MODE_LOCAL;
    }

    public function syncEnvironment(): string
    {
        return $this->mode() === self::MODE_PRODUCTION ? 'production' : 'homologation';
    }

    public function save(string $mode): void
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, [self::MODE_LOCAL, self::MODE_PRODUCTION], true)) {
            throw new InvalidArgumentException('Modo de execução inválido.');
        }

        $this->settings->set('runtime.mode', $mode, 'Modo explícito do portal CIF: local/homologação ou produção.');
        $this->settings->set('sync.environment', $mode === self::MODE_PRODUCTION ? 'production' : 'homologation', 'Ambiente criptográfico derivado do modo do portal.');
    }

    public function isProduction(): bool
    {
        return $this->mode() === self::MODE_PRODUCTION;
    }

    public function label(): string
    {
        return $this->isProduction() ? 'PRODUÇÃO' : 'LOCAL / HOMOLOGAÇÃO';
    }

    public function productionSiteIsSecure(): bool
    {
        foreach ([home_url('/'), site_url('/')] as $url) {
            $scheme = strtolower((string)wp_parse_url($url, PHP_URL_SCHEME));
            if ($scheme !== 'https') {
                return false;
            }
        }
        return true;
    }

    public function keyEnvironmentHint(string $keyId): ?string
    {
        $keyId = strtolower($keyId);
        if (str_starts_with($keyId, 'smtt-cif-hml-')) {
            return 'homologation';
        }
        if (str_starts_with($keyId, 'smtt-cif-prod-')) {
            return 'production';
        }
        return null;
    }

    public function keyIdCompatible(string $keyId): bool
    {
        $hint = $this->keyEnvironmentHint($keyId);
        return $hint === null || hash_equals($this->syncEnvironment(), $hint);
    }
}
