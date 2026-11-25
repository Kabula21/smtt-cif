<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\IssuanceProcess;

use InvalidArgumentException;

final class ProcessType
{
    public const PRIMEIRA_EMISSAO = 'PRIMEIRA_EMISSAO';
    public const SEGUNDA_VIA = 'SEGUNDA_VIA';
    public const RENOVACAO = 'RENOVACAO';
    public const RETIFICACAO = 'RETIFICACAO';
    public const DANO = 'DANO';
    public const PERDA = 'PERDA';
    public const FURTO = 'FURTO';
    public const ROUBO = 'ROUBO';
    public const SUBSTITUICAO = 'SUBSTITUICAO';

    /** @return array<string,string> */
    public static function options(): array
    {
        return [
            self::PRIMEIRA_EMISSAO => 'Primeira emissão',
            self::SEGUNDA_VIA => 'Segunda via',
            self::RENOVACAO => 'Renovação',
            self::RETIFICACAO => 'Retificação de dados',
            self::DANO => 'Desgaste / dano',
            self::PERDA => 'Extravio — perda',
            self::FURTO => 'Extravio — furto',
            self::ROUBO => 'Extravio — roubo',
            self::SUBSTITUICAO => 'Substituição',
        ];
    }

    public static function normalize(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!array_key_exists($value, self::options())) {
            throw new InvalidArgumentException('Tipo de processo de emissão inválido.');
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

    private function __construct()
    {
    }
}
