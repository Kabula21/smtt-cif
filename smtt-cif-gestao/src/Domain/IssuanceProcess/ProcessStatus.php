<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\IssuanceProcess;

use InvalidArgumentException;

final class ProcessStatus
{
    public const RASCUNHO = 'RASCUNHO';
    public const EM_ANALISE = 'EM_ANALISE';
    public const PENDENTE_DOCUMENTACAO = 'PENDENTE_DOCUMENTACAO';
    public const APROVADO = 'APROVADO';
    public const EM_EXECUCAO = 'EM_EXECUCAO';
    public const CONCLUIDO = 'CONCLUIDO';
    public const INDEFERIDO = 'INDEFERIDO';
    public const CANCELADO = 'CANCELADO';

    /** @return array<string,string> */
    public static function options(): array
    {
        return [
            self::RASCUNHO => 'Rascunho (legado)',
            self::EM_ANALISE => 'Em tramitação',
            self::PENDENTE_DOCUMENTACAO => 'Pendente de regularização',
            self::APROVADO => 'Aprovado',
            self::EM_EXECUCAO => 'Em execução',
            self::CONCLUIDO => 'Concluído',
            self::INDEFERIDO => 'Indeferido',
            self::CANCELADO => 'Cancelado',
        ];
    }

    public static function label(string $value): string
    {
        return self::options()[$value] ?? $value;
    }

    public static function assertTransition(string $from, string $to): void
    {
        $allowed = self::transitions()[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'Transição de processo não permitida: %s → %s.',
                self::label($from),
                self::label($to)
            ));
        }
    }

    /** @return array<string,list<string>> */
    public static function transitions(): array
    {
        return [
            self::RASCUNHO => [self::EM_ANALISE, self::CANCELADO],
            self::EM_ANALISE => [self::PENDENTE_DOCUMENTACAO, self::APROVADO, self::INDEFERIDO, self::CANCELADO],
            self::PENDENTE_DOCUMENTACAO => [self::EM_ANALISE, self::CANCELADO],
            self::APROVADO => [self::EM_EXECUCAO, self::CANCELADO],
            self::EM_EXECUCAO => [self::CONCLUIDO],
            self::CONCLUIDO => [],
            self::INDEFERIDO => [],
            self::CANCELADO => [],
        ];
    }

    public static function isTerminal(string $value): bool
    {
        return in_array($value, [self::CONCLUIDO, self::INDEFERIDO, self::CANCELADO], true);
    }

    private function __construct()
    {
    }
}
