<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\Emission;

use InvalidArgumentException;

final class ValidationCode
{
    public static function build(int $cycle, int $sequence): string
    {
        if ($cycle < 1 || $cycle > 99) {
            throw new InvalidArgumentException('O ciclo da CIF deve estar entre 01 e 99.');
        }
        if ($sequence < 1 || $sequence > 9999) {
            throw new InvalidArgumentException('O sequencial global da CIF deve estar entre 0001 e 9999.');
        }
        return sprintf('AJU%02dSEBR%04d', $cycle, $sequence);
    }

    public static function isValid(string $code): bool
    {
        return preg_match('/^AJU(?:0[1-9]|[1-9][0-9])SEBR(?:000[1-9]|00[1-9][0-9]|0[1-9][0-9]{2}|[1-9][0-9]{3})$/', $code) === 1;
    }

    private function __construct()
    {
    }
}
