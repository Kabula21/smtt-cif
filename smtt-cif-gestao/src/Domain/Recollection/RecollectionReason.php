<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\Recollection;

use InvalidArgumentException;

final class RecollectionReason
{
    public const SUBSTITUICAO_CIF = 'SUBSTITUICAO_CIF';
    public const SUBSTITUICAO_NOVO_MODELO = 'SUBSTITUICAO_NOVO_MODELO';
    public const EXONERACAO = 'EXONERACAO';
    public const VACANCIA = 'VACANCIA';
    public const FALECIMENTO = 'FALECIMENTO';
    public const RECUPERACAO_APOS_SUBSTITUICAO = 'RECUPERACAO_APOS_SUBSTITUICAO';

    /** @return array<string,string> */
    public static function administrativeOptions(): array
    {
        return [
            self::SUBSTITUICAO_NOVO_MODELO => 'Substituição por novo modelo',
            self::EXONERACAO => 'Exoneração',
            self::VACANCIA => 'Vacância',
            self::FALECIMENTO => 'Falecimento',
        ];
    }

    public static function normalizeAdministrative(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!isset(self::administrativeOptions()[$value])) {
            throw new InvalidArgumentException('Motivo de recolhimento inválido.');
        }
        return $value;
    }

    public static function label(string $value): string
    {
        return self::administrativeOptions()[$value] ?? match ($value) {
            self::SUBSTITUICAO_CIF => 'Substituição de CIF',
            self::RECUPERACAO_APOS_SUBSTITUICAO => 'Recuperação após substituição',
            default => $value,
        };
    }

    private function __construct() {}
}
