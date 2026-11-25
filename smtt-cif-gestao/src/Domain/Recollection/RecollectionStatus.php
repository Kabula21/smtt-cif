<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\Recollection;

final class RecollectionStatus
{
    public const PENDENTE = 'PENDENTE';
    public const RECOLHIDA = 'RECOLHIDA';
    public const NAO_RECOLHIDA_JUSTIFICADA = 'NAO_RECOLHIDA_JUSTIFICADA';
    public const INUTILIZADA = 'INUTILIZADA';

    public static function label(string $value): string
    {
        return match ($value) {
            self::PENDENTE => 'Pendente de recolhimento',
            self::RECOLHIDA => 'Recolhida',
            self::NAO_RECOLHIDA_JUSTIFICADA => 'Não recolhida — justificada',
            self::INUTILIZADA => 'Inutilizada',
            default => $value,
        };
    }

    private function __construct() {}
}
