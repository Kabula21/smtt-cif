<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\Agent;

use InvalidArgumentException;

final class FunctionalStatus
{
    /** @return array<string,string> */
    public static function options(): array
    {
        return [
            'ATIVO' => 'Ativo',
            'AFASTADO' => 'Afastado',
            'EXONERADO' => 'Exonerado',
            'VACANCIA' => 'Vacância',
            'FALECIDO' => 'Falecido',
        ];
    }

    public static function normalize(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!array_key_exists($code, self::options())) {
            throw new InvalidArgumentException('Situação funcional inválida.');
        }
        return $code;
    }

    public static function label(string $code): string
    {
        return self::options()[$code] ?? $code;
    }

    private function __construct()
    {
    }
}
