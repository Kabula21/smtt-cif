<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\Emission;

final class EmissionStatus
{
    public const PENDENTE_ATIVACAO = 'PENDENTE_ATIVACAO';
    public const ATIVA = 'ATIVA';
    public const SUSPENSA = 'SUSPENSA';
    public const ENCERRADA = 'ENCERRADA';

    public static function label(string $status): string
    {
        return match ($status) {
            self::PENDENTE_ATIVACAO => 'Pendente de ativação',
            self::ATIVA => 'Ativa',
            self::SUSPENSA => 'Suspensa',
            self::ENCERRADA => 'Encerrada',
            default => $status,
        };
    }

    private function __construct() {}
}
