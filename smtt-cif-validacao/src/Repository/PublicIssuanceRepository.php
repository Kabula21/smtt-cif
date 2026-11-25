<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Repository;

use mysqli;
use RuntimeException;

final class PublicIssuanceRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findForUpdate(string $emissionUuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM public_issuances WHERE emission_uuid=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $emissionUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $item */
    public function insert(array $item): void
    {
        $tokenHash = $item['token_hash'];
        $projectionHash = $item['projection_hash_raw'];
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO public_issuances(
    emission_uuid,token_hash,code,name,registration,admission_date,issued_at,public_status,record_version,
    projection_hash,source_updated_at,first_synced_at,last_synced_at
) VALUES(?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param(
            'ssssssssiss',
            $item['emission_id'], $tokenHash, $item['code'], $item['name'], $item['registration'], $item['admission_date_db'],
            $item['issued_at_db'], $item['public_status'], $item['record_version'], $projectionHash, $item['source_updated_at_db']
        );
        $stmt->execute();
        $stmt->close();
    }

    /** @param array<string,mixed> $item */
    public function update(array $item): void
    {
        $tokenHash = $item['token_hash'];
        $projectionHash = $item['projection_hash_raw'];
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE public_issuances SET
    token_hash=?, code=?, name=?, registration=?, admission_date=?, issued_at=?, public_status=?, record_version=?,
    projection_hash=?, source_updated_at=?, last_synced_at=UTC_TIMESTAMP(6)
WHERE emission_uuid=?
SQL);
        $stmt->bind_param(
            'sssssssisss',
            $tokenHash, $item['code'], $item['name'], $item['registration'], $item['admission_date_db'], $item['issued_at_db'],
            $item['public_status'], $item['record_version'], $projectionHash, $item['source_updated_at_db'], $item['emission_id']
        );
        $stmt->execute();
        if ($stmt->affected_rows < 0) {
            $stmt->close();
            throw new RuntimeException('Falha ao atualizar projeção pública.');
        }
        $stmt->close();
    }

    /** @return array<string,mixed>|null */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare('SELECT emission_uuid,code,name,registration,admission_date,issued_at,public_status,record_version,last_synced_at FROM public_issuances WHERE token_hash=? LIMIT 1');
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array{record_version:int,projection_hash:string}|null */
    public function reconcileState(string $emissionUuid): ?array
    {
        $stmt = $this->db->prepare('SELECT record_version,projection_hash FROM public_issuances WHERE emission_uuid=? LIMIT 1');
        $stmt->bind_param('s', $emissionUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        return ['record_version' => (int)$row['record_version'], 'projection_hash' => bin2hex((string)$row['projection_hash'])];
    }

    public function count(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS total FROM public_issuances');
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['total'] ?? 0);
    }

    public function lastSyncAt(): ?string
    {
        $result = $this->db->query('SELECT MAX(last_synced_at) AS last_sync FROM public_issuances');
        $row = $result->fetch_assoc();
        $result->free();
        return isset($row['last_sync']) && is_string($row['last_sync']) ? $row['last_sync'] : null;
    }
}
