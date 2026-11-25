<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Repository;

use mysqli;
use RuntimeException;

final class SyncRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /** @return array{public_key:string,status:string}|null */
    public function acceptedKey(string $keyId): ?array
    {
        $stmt = $this->db->prepare("SELECT public_key,status FROM sync_public_keys WHERE key_id=? AND status='ACCEPTED' AND valid_from<=UTC_TIMESTAMP(6) AND (valid_until IS NULL OR valid_until>=UTC_TIMESTAMP(6)) LIMIT 1");
        $stmt->bind_param('s', $keyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? ['public_key' => (string)$row['public_key'], 'status' => (string)$row['status']] : null;
    }

    public function installKey(string $keyId, string $publicKey): void
    {
        $algorithm = 'Ed25519';
        $fingerprint = hash('sha256', $publicKey, true);
        $status = 'ACCEPTED';
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO sync_public_keys(key_id,algorithm,public_key,fingerprint,status,valid_from,valid_until,created_at,updated_at)
VALUES(?,?,?,?,?,UTC_TIMESTAMP(6),NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE algorithm=VALUES(algorithm),public_key=VALUES(public_key),fingerprint=VALUES(fingerprint),status='ACCEPTED',valid_until=NULL,updated_at=UTC_TIMESTAMP(6)
SQL);
        $stmt->bind_param('sssss', $keyId, $algorithm, $publicKey, $fingerprint, $status);
        $stmt->execute();
        $stmt->close();
    }

    public function retireKey(string $keyId): void
    {
        $stmt = $this->db->prepare("UPDATE sync_public_keys SET status='RETIRED',valid_until=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE key_id=? AND status='ACCEPTED'");
        $stmt->bind_param('s', $keyId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return list<array<string,mixed>> */
    public function keys(): array
    {
        $result = $this->db->query('SELECT key_id,algorithm,HEX(fingerprint) AS fingerprint,status,valid_from,valid_until,created_at FROM sync_public_keys ORDER BY created_at DESC');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $row['fingerprint'] = strtolower((string)$row['fingerprint']);
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    public function rememberRequest(string $requestId, string $keyId, string $timestampDb, string $endpoint): bool
    {
        try {
            $stmt = $this->db->prepare('INSERT INTO sync_replay_guard(request_id,key_id,request_timestamp,received_at,endpoint) VALUES(?,?,?,UTC_TIMESTAMP(6),?)');
            $stmt->bind_param('ssss', $requestId, $keyId, $timestampDb, $endpoint);
            $stmt->execute();
            $stmt->close();
            $this->db->query("DELETE FROM sync_replay_guard WHERE received_at < UTC_TIMESTAMP(6) - INTERVAL 7 DAY");
            return true;
        } catch (\mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /** @return array{body_hash:string,result:string}|null */
    public function event(string $eventId): ?array
    {
        $stmt = $this->db->prepare('SELECT body_hash,result FROM sync_events_received WHERE event_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $eventId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? ['body_hash' => (string)$row['body_hash'], 'result' => (string)$row['result']] : null;
    }

    public function recordEvent(string $eventId, string $eventType, int $payloadVersion, string $bodyHash, string $result, string $requestId): void
    {
        $stmt = $this->db->prepare('INSERT INTO sync_events_received(event_id,event_type,payload_version,body_hash,result,first_request_id,received_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $stmt->bind_param('ssisss', $eventId, $eventType, $payloadVersion, $bodyHash, $result, $requestId);
        $stmt->execute();
        $stmt->close();
    }

    public function log(string $endpoint, ?string $requestId, ?string $keyId, string $result, int $httpStatus, ?string $summary): void
    {
        if ($summary !== null) {
            $summary = function_exists('mb_substr') ? mb_substr($summary, 0, 500, 'UTF-8') : substr($summary, 0, 500);
        }
        $stmt = $this->db->prepare('INSERT INTO sync_log(occurred_at,endpoint,request_id,key_id,result,http_status,summary) VALUES(UTC_TIMESTAMP(6),?,?,?,?,?,?)');
        $stmt->bind_param('ssssis', $endpoint, $requestId, $keyId, $result, $httpStatus, $summary);
        $stmt->execute();
        $stmt->close();
    }

    /** @return list<array<string,mixed>> */
    public function recentLogs(int $limit = 20): array
    {
        $limit = min(max($limit, 1), 100);
        $result = $this->db->query('SELECT occurred_at,endpoint,request_id,key_id,result,http_status,summary FROM sync_log ORDER BY id DESC LIMIT ' . $limit);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    public function replayCount(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS total FROM sync_replay_guard');
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['total'] ?? 0);
    }
}
