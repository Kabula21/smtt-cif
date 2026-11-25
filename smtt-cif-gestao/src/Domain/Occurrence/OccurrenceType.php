<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\Occurrence;

use InvalidArgumentException;

final class OccurrenceType
{
    public const PERDA = 'PERDA';
    public const FURTO = 'FURTO';
    public const ROUBO = 'ROUBO';
    public const DANO = 'DANO';
    public const DESGASTE = 'DESGASTE';

    /** @return array<string,string> */
    public static function options(): array
    {
        return [
            self::PERDA => 'Perda',
            self::FURTO => 'Furto',
            self::ROUBO => 'Roubo',
            self::DANO => 'Dano',
            self::DESGASTE => 'Desgaste',
        ];
    }

    public static function normalize(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!isset(self::options()[$value])) {
            throw new InvalidArgumentException('Tipo de ocorrência inválido.');
        }
        return $value;
    }

    public static function label(string $value): string
    {
        return self::options()[$value] ?? $value;
    }

    public static function isExtravio(string $value): bool
    {
        return in_array($value, [self::PERDA, self::FURTO, self::ROUBO], true);
    }

    private function __construct() {}
}
