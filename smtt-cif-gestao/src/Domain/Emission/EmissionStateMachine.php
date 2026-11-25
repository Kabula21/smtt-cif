<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\Emission;

use InvalidArgumentException;

final class EmissionStateMachine
{
    public static function assertValidityTransition(string $from, string $to): void
    {
        $allowed = [
            EmissionStatus::PENDENTE_ATIVACAO => [EmissionStatus::ATIVA, EmissionStatus::ENCERRADA],
            EmissionStatus::ATIVA => [EmissionStatus::SUSPENSA, EmissionStatus::ENCERRADA],
            EmissionStatus::SUSPENSA => [EmissionStatus::ATIVA, EmissionStatus::ENCERRADA],
            EmissionStatus::ENCERRADA => [],
        ];

        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw new InvalidArgumentException(sprintf(
                'Transição de validade não permitida: %s → %s.',
                EmissionStatus::label($from),
                EmissionStatus::label($to)
            ));
        }
    }

    public static function assertPhysicalTransition(string $from, string $to): void
    {
        $allowed = [
            PhysicalStatus::NAO_PRODUZIDA => [PhysicalStatus::EM_GUARDA_SMTT],
            PhysicalStatus::EM_GUARDA_SMTT => [PhysicalStatus::ENTREGUE, PhysicalStatus::INUTILIZADA],
            PhysicalStatus::ENTREGUE => [PhysicalStatus::NAO_LOCALIZADA, PhysicalStatus::RECOLHIDA],
            PhysicalStatus::NAO_LOCALIZADA => [PhysicalStatus::ENTREGUE, PhysicalStatus::RECOLHIDA],
            PhysicalStatus::RECOLHIDA => [PhysicalStatus::INUTILIZADA],
            PhysicalStatus::INUTILIZADA => [],
        ];

        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw new InvalidArgumentException(sprintf(
                'Transição física não permitida: %s → %s.',
                PhysicalStatus::label($from),
                PhysicalStatus::label($to)
            ));
        }
    }

    private function __construct()
    {
    }
}
