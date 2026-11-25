<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use SMTT\CIF\Gestao\Audit\AuditChain;
use SMTT\CIF\Gestao\Repository\AuditRepository;

final class AuditIntegrityService
{
    public function __construct(private readonly AuditRepository $repository)
    {
    }

    /** @return array{configured:bool,valid:bool,legacy_count:int,chained_count:int,first_invalid_id:?int,message:string} */
    public function verify(): array
    {
        $anchor = $this->repository->chainAnchor();
        if ($anchor === null) {
            return [
                'configured' => false,
                'valid' => false,
                'legacy_count' => 0,
                'chained_count' => 0,
                'first_invalid_id' => null,
                'message' => 'O encadeamento criptográfico ainda não foi inicializado pela migration de auditoria.',
            ];
        }

        $legacyLastId = (int)$anchor['legacy_last_id'];
        $legacyCount = (int)($anchor['legacy_count'] ?? $legacyLastId);
        $previous = (string)$anchor['genesis_hash'];
        $rows = $this->repository->chainedRecords($legacyLastId);
        $count = 0;
        foreach ($rows as $row) {
            ++$count;
            if (!is_string($row['previous_hash']) || !hash_equals($previous, (string)$row['previous_hash'])) {
                return [
                    'configured' => true,
                    'valid' => false,
                    'legacy_count' => $legacyCount,
                    'chained_count' => $count,
                    'first_invalid_id' => (int)$row['id'],
                    'message' => 'A continuidade do encadeamento foi interrompida.',
                ];
            }
            $expected = AuditChain::hash($previous, $row);
            if (!is_string($row['record_hash']) || !hash_equals($expected, (string)$row['record_hash'])) {
                return [
                    'configured' => true,
                    'valid' => false,
                    'legacy_count' => $legacyCount,
                    'chained_count' => $count,
                    'first_invalid_id' => (int)$row['id'],
                    'message' => 'O hash de um registro de auditoria não confere com o conteúdo persistido.',
                ];
            }
            $previous = (string)$row['record_hash'];
        }

        $expectedLastId = $rows === [] ? $legacyLastId : (int)$rows[array_key_last($rows)]['id'];
        if (!is_string($anchor['last_hash'] ?? null) || !hash_equals($previous, (string)$anchor['last_hash']) || (int)$anchor['last_audit_id'] !== $expectedLastId) {
            return [
                'configured' => true,
                'valid' => false,
                'legacy_count' => $legacyCount,
                'chained_count' => $count,
                'first_invalid_id' => $expectedLastId > $legacyLastId ? $expectedLastId : null,
                'message' => 'A âncora final da cadeia não corresponde ao último registro de auditoria encadeado.',
            ];
        }

        return [
            'configured' => true,
            'valid' => true,
            'legacy_count' => $legacyCount,
            'chained_count' => $count,
            'first_invalid_id' => null,
            'message' => $count === 0
                ? 'Encadeamento inicializado. Ainda não há novos registros encadeados.'
                : 'Encadeamento criptográfico íntegro para todos os registros posteriores à ativação.',
        ];
    }
}
