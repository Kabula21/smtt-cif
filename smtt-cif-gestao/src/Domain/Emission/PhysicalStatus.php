<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\Emission;

final class PhysicalStatus
{
    public const NAO_PRODUZIDA = 'NAO_PRODUZIDA';
    public const EM_GUARDA_SMTT = 'EM_GUARDA_SMTT';
    public const ENTREGUE = 'ENTREGUE';
    public const NAO_LOCALIZADA = 'NAO_LOCALIZADA';
    public const RECOLHIDA = 'RECOLHIDA';
    public const INUTILIZADA = 'INUTILIZADA';

    public static function label(string $status): string
    {
        return match ($status) {
            self::NAO_PRODUZIDA => 'Não produzida',
            self::EM_GUARDA_SMTT => 'Em guarda na SMTT',
            self::ENTREGUE => 'Entregue',
            self::NAO_LOCALIZADA => 'Não localizada',
            self::RECOLHIDA => 'Recolhida',
            self::INUTILIZADA => 'Inutilizada',
            default => $status,
        };
    }

    private function __construct() {}
}
