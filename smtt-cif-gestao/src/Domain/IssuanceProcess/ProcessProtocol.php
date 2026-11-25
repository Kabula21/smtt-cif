<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\IssuanceProcess;

use InvalidArgumentException;

final class ProcessProtocol
{
    public static function build(int $year, int $sequence): string
    {
        if ($year < 2000 || $year > 9999) {
            throw new InvalidArgumentException('Ano inválido para o protocolo CIF.');
        }
        if ($sequence < 1) {
            throw new InvalidArgumentException('Sequencial inválido para o protocolo CIF.');
        }

        return sprintf('CIF-%04d-%06d', $year, $sequence);
    }

    public static function isValid(string $protocol): bool
    {
        return preg_match('/^CIF-\d{4}-\d{6,}$/', $protocol) === 1;
    }

    private function __construct()
    {
    }
}
