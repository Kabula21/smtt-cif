<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Repository\AgentRepository;
use SMTT\CIF\Gestao\Security\CifUser;

final class AgentExportService
{
    public function __construct(
        private readonly AgentRepository $repository,
        private readonly AuditLogger $audit
    ) {
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function export(array $filters, CifUser $actor): array
    {
        $probe = $this->repository->search($filters, 1, 25);
        if ($probe['total'] > 5000) {
            throw new RuntimeException('A exportação está limitada a 5.000 registros. Refine os filtros antes de exportar.');
        }

        $rows = $this->repository->exportRows($filters, max(1, $probe['total']));
        $this->audit->log(
            'AGENTES_EXPORTADOS',
            'agente_lista',
            null,
            null,
            $actor,
            'WEB',
            null,
            [
                'quantidade' => count($rows),
                'filtros' => $this->safeFilters($filters),
                'campos_sensiveis' => false,
            ]
        );
        return $rows;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function safeFilters(array $filters): array
    {
        $safe = $filters;
        unset($safe['cpf_lookup']);
        if (isset($safe['cpf']) && (string) $safe['cpf'] !== '') {
            $safe['cpf'] = '[FILTRO CPF EXATO]';
        }
        return $safe;
    }
}
