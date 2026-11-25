<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\Occurrence;

final class OccurrenceStatus
{
    public const ABERTA = 'ABERTA';
    public const REGISTRADA = 'REGISTRADA';
    public const RECUPERADA_REATIVADA = 'RECUPERADA_REATIVADA';
    public const RECUPERADA_APOS_SUBSTITUICAO = 'RECUPERADA_APOS_SUBSTITUICAO';

    public static function label(string $value): string
    {
        return match ($value) {
            self::ABERTA => 'Aberta',
            self::REGISTRADA => 'Registrada',
            self::RECUPERADA_REATIVADA => 'Recuperada e reativada',
            self::RECUPERADA_APOS_SUBSTITUICAO => 'Recuperada após substituição',
            default => $value,
        };
    }

    private function __construct() {}
}
