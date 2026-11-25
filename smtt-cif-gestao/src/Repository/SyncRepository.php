<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use JsonException;
use RuntimeException;
use mysqli;
use SMTT\CIF\Gestao\Support\Uuid;

final class SyncRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $result = $this->db->query('SELECT * FROM sync_state WHERE id=1 LIMIT 1');
        $row = $result->fetch_assoc() ?: [];
        $result->free();
        return $row;
    }

    /** @return array<string,int|string|null> */
    public function queueStats(): array
    {
        $result = $this->db->query(<<<'SQL'
SELECT
  SUM(status='PENDING') AS pending,
  SUM(status='RETRY') AS retrying,
  SUM(status='PROCESSING') AS processing,
  SUM(status='DELIVERED') AS delivered,
  SUM(status='ATTENTION_REQUIRED') AS attention,
  MIN(CASE WHEN status IN ('PENDING','RETRY','PROCESSING','ATTENTION_REQUIRED') THEN criado_em END) AS oldest_open,
  COUNT(*) AS total
FROM sync_outbox
SQL);
        $row = $result->fetch_assoc() ?: [];
        $result->free();
        return [
            'pending' => (int)($row['pending'] ?? 0),
            'retrying' => (int)($row['retrying'] ?? 0),
            'processing' => (int)($row['processing'] ?? 0),
            'delivered' => (int)($row['delivered'] ?? 0),
            'attention' => (int)($row['attention'] ?? 0),
            'oldest_open' => $row['oldest_open'] ?? null,
            'total' => (int)($row['total'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function recentEvents(int $limit = 30): array
    {
        $limit = min(max($limit, 1), 100);
        $result = $this->db->query(<<<SQL
SELECT o.id, o.event_uuid, o.event_type, o.aggregate_type, o.aggregate_uuid, o.aggregate_version,
       o.status, o.tentativas, o.criado_em, o.proxima_tentativa_em, o.enviado_em,
       o.ultimo_erro_codigo, o.ultimo_erro_resumo,
       a.http_status AS ultimo_http_status, a.resultado AS ultimo_resultado, a.request_time AS ultima_tentativa_em
FROM sync_outbox o
LEFT JOIN sync_attempts a ON a.id=(
    SELECT a2.id FROM sync_attempts a2 WHERE a2.outbox_id=o.id ORDER BY a2.numero_tentativa DESC LIMIT 1
)
ORDER BY o.id DESC
LIMIT {$limit}
SQL);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        $stmt = $this->db->prepare('SELECT valor FROM configuracoes WHERE chave=? LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row) || !isset($row['valor'])) {
            return $default;
        }
        try {
            return json_decode((string)$row['valor'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $default;
        }
    }

    public function setConfig(string $key, mixed $value, string $description, int $actorId): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO configuracoes (chave, valor, descricao, atualizado_por, atualizado_em)
VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE valor=VALUES(valor), descricao=VALUES(descricao), atualizado_por=VALUES(atualizado_por), atualizado_em=VALUES(atualizado_em)
SQL);
        $stmt->bind_param('sssi', $key, $json, $description, $actorId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return list<array<string,mixed>> */
    public function signingKeys(): array
    {
        $result = $this->db->query(<<<'SQL'
SELECT key_id, algoritmo, public_key_fingerprint, vigencia_inicio, vigencia_fim, status, criado_em
FROM sync_chaves_assinatura
ORDER BY criado_em DESC, key_id DESC
SQL);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $row['fingerprint_hex'] = isset($row['public_key_fingerprint']) && is_string($row['public_key_fingerprint'])
                ? bin2hex($row['public_key_fingerprint']) : null;
            unset($row['public_key_fingerprint']);
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function activeSigningKey(): ?array
    {
        $result = $this->db->query("SELECT * FROM sync_chaves_assinatura WHERE status='ATIVA' ORDER BY vigencia_inicio DESC LIMIT 1");
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function signingKey(string $keyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sync_chaves_assinatura WHERE key_id=? LIMIT 1');
        $stmt->bind_param('s', $keyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function insertSigningKey(string $keyId, string $privateReference, string $fingerprint): void
    {
        $algorithm = 'Ed25519';
        $status = 'PENDENTE';
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO sync_chaves_assinatura (
  key_id, algoritmo, private_key_reference, public_key_fingerprint,
  vigencia_inicio, vigencia_fim, status, criado_em
) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), NULL, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('sssss', $keyId, $algorithm, $privateReference, $fingerprint, $status);
        $stmt->execute();
        $stmt->close();
    }

    public function activateSigningKey(string $keyId): void
    {
        $this->db->query("UPDATE sync_chaves_assinatura SET status='RETIRADA', vigencia_fim=UTC_TIMESTAMP(6) WHERE status='ATIVA'");
        $stmt = $this->db->prepare("UPDATE sync_chaves_assinatura SET status='ATIVA', vigencia_inicio=UTC_TIMESTAMP(6), vigencia_fim=NULL WHERE key_id=? AND status IN ('PENDENTE','RETIRADA')");
        $stmt->bind_param('s', $keyId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Não foi possível ativar a chave de sincronização selecionada.');
        }
        $stmt->close();
    }

    /** @return array<string,mixed>|null */
    public function claimNext(string $workerId, int $lockTimeoutSeconds = 600): ?array
    {
        $lockTimeoutSeconds = max(60, $lockTimeoutSeconds);
        $this->db->begin_transaction();
        try {
            $cutoff = gmdate('Y-m-d H:i:s', time() - $lockTimeoutSeconds);
            $stmt = $this->db->prepare(<<<'SQL'
SELECT * FROM sync_outbox
WHERE (
    (status IN ('PENDING','RETRY') AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em <= UTC_TIMESTAMP(6)) AND disponivel_em <= UTC_TIMESTAMP(6))
    OR (status='PROCESSING' AND locked_em < ?)
)
ORDER BY id ASC
LIMIT 1
FOR UPDATE
SQL);
            $stmt->bind_param('s', $cutoff);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!is_array($row)) {
                $this->db->commit();
                return null;
            }
            $id = (int)$row['id'];
            $upd = $this->db->prepare("UPDATE sync_outbox SET status='PROCESSING', locked_em=UTC_TIMESTAMP(6), locked_by=? WHERE id=?");
            $upd->bind_param('si', $workerId, $id);
            $upd->execute();
            $upd->close();
            $this->db->commit();
            $row['status'] = 'PROCESSING';
            $row['locked_by'] = $workerId;
            return $row;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function freezeOutboxPayload(int $id, array $payload, int $aggregateVersion): array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json, true);
        $stmt = $this->db->prepare('UPDATE sync_outbox SET payload=?, payload_hash=?, aggregate_version=? WHERE id=? AND status=\'PROCESSING\'');
        $stmt->bind_param('ssii', $json, $hash, $aggregateVersion, $id);
        $stmt->execute();
        $stmt->close();
        return ['payload' => $json, 'payload_hash' => $hash, 'aggregate_version' => $aggregateVersion];
    }

    public function nextAttemptNumber(int $outboxId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(numero_tentativa),0)+1 AS n FROM sync_attempts WHERE outbox_id=?');
        $stmt->bind_param('i', $outboxId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['n'] ?? 1);
    }

    public function insertAttempt(array $data): void
    {
        $responseHash = $data['response_hash'] ?? null;
        $httpStatus = $data['http_status'] ?? null;
        $duration = $data['duracao_ms'] ?? null;
        $errorType = $data['erro_tipo'] ?? null;
        $errorSummary = $data['erro_resumo'] ?? null;
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO sync_attempts (
  outbox_id, numero_tentativa, request_id, request_time, key_id, algoritmo_assinatura,
  http_status, resultado, duracao_ms, response_hash, erro_tipo, erro_resumo, criado_em
) VALUES (?, ?, ?, ?, ?, 'Ed25519', ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param(
            'iisssisisss',
            $data['outbox_id'], $data['numero_tentativa'], $data['request_id'], $data['request_time'], $data['key_id'],
            $httpStatus, $data['resultado'], $duration, $responseHash, $errorType, $errorSummary
        );
        $stmt->execute();
        $stmt->close();
    }

    /** @param list<array{emission_id:string,record_version:int,projection_hash:string}> $items */
    public function markDelivered(int $outboxId, array $items, int $attempts): void
    {
        $stmt = $this->db->prepare("UPDATE sync_outbox SET status='DELIVERED', tentativas=?, enviado_em=UTC_TIMESTAMP(6), locked_em=NULL, locked_by=NULL, proxima_tentativa_em=NULL, ultimo_erro_codigo=NULL, ultimo_erro_resumo=NULL WHERE id=?");
        $stmt->bind_param('ii', $attempts, $outboxId);
        $stmt->execute();
        $stmt->close();
        foreach ($items as $item) {
            $type = 'CIF';
            $uuid = $item['emission_id'];
            $version = (int)$item['record_version'];
            $hash = hex2bin($item['projection_hash']);
            if (!is_string($hash) || strlen($hash) !== 32) {
                continue;
            }
            $up = $this->db->prepare(<<<'SQL'
UPDATE sync_projection_state
SET synced_hash=IF(? >= synced_version, ?, synced_hash),
    synced_version=GREATEST(synced_version, ?),
    last_synced_at=UTC_TIMESTAMP(6), last_error_at=NULL
WHERE aggregate_type=? AND aggregate_uuid=?
SQL);
            $up->bind_param('isiss', $version, $hash, $version, $type, $uuid);
            $up->execute();
            $up->close();
        }
        $this->db->query("UPDATE sync_state SET ultima_tentativa_em=UTC_TIMESTAMP(6), ultimo_sucesso_em=UTC_TIMESTAMP(6), falhas_consecutivas=0, atualizado_em=UTC_TIMESTAMP(6) WHERE id=1");
    }

    public function markRetry(int $outboxId, int $attempts, int $delaySeconds, string $errorCode, string $summary): void
    {
        $next = gmdate('Y-m-d H:i:s', time() + $delaySeconds);
        $stmt = $this->db->prepare("UPDATE sync_outbox SET status='RETRY', tentativas=?, proxima_tentativa_em=?, locked_em=NULL, locked_by=NULL, ultimo_erro_codigo=?, ultimo_erro_resumo=? WHERE id=?");
        $stmt->bind_param('isssi', $attempts, $next, $errorCode, $summary, $outboxId);
        $stmt->execute();
        $stmt->close();
        $this->recordGlobalFailure();
    }

    public function markAttention(int $outboxId, int $attempts, string $errorCode, string $summary): void
    {
        $stmt = $this->db->prepare("UPDATE sync_outbox SET status='ATTENTION_REQUIRED', tentativas=?, proxima_tentativa_em=NULL, locked_em=NULL, locked_by=NULL, ultimo_erro_codigo=?, ultimo_erro_resumo=? WHERE id=?");
        $stmt->bind_param('issi', $attempts, $errorCode, $summary, $outboxId);
        $stmt->execute();
        $stmt->close();
        $this->recordGlobalFailure();
    }

    public function requeue(string $eventUuid): void
    {
        $stmt = $this->db->prepare("UPDATE sync_outbox SET status='PENDING', proxima_tentativa_em=NULL, locked_em=NULL, locked_by=NULL, ultimo_erro_codigo=NULL, ultimo_erro_resumo=NULL WHERE event_uuid=? AND status IN ('ATTENTION_REQUIRED','RETRY')");
        $stmt->bind_param('s', $eventUuid);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('O evento não está em condição de ser reenfileirado.');
        }
        $stmt->close();
    }

    public function recordHeartbeatAttempt(bool $success): void
    {
        if ($success) {
            $this->db->query("UPDATE sync_state SET ultimo_heartbeat_envio_em=UTC_TIMESTAMP(6), ultimo_heartbeat_ok_em=UTC_TIMESTAMP(6), ultimo_sucesso_em=UTC_TIMESTAMP(6), falhas_consecutivas=0, atualizado_em=UTC_TIMESTAMP(6) WHERE id=1");
        } else {
            $this->db->query("UPDATE sync_state SET ultimo_heartbeat_envio_em=UTC_TIMESTAMP(6), falhas_consecutivas=falhas_consecutivas+1, atualizado_em=UTC_TIMESTAMP(6) WHERE id=1");
        }
    }

    public function heartbeatDue(int $intervalSeconds): bool
    {
        $intervalSeconds = max(60, $intervalSeconds);
        $stmt = $this->db->prepare('SELECT ultimo_heartbeat_envio_em FROM sync_state WHERE id=1');
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $last = $row['ultimo_heartbeat_envio_em'] ?? null;
        if (!is_string($last) || $last === '') {
            return true;
        }
        $ts = strtotime($last . ' UTC');
        return $ts === false || (time() - $ts) >= $intervalSeconds;
    }

    /** @return list<array{emission_id:string,desired_version:int,desired_hash:string}> */
    public function projectionStates(int $limit = 500, int $offset = 0): array
    {
        $limit = min(max($limit, 1), 1000);
        $offset = max($offset, 0);
        $result = $this->db->query("SELECT aggregate_uuid, desired_version, desired_hash FROM sync_projection_state WHERE aggregate_type='CIF' ORDER BY aggregate_uuid LIMIT {$limit} OFFSET {$offset}");
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'emission_id' => (string)$row['aggregate_uuid'],
                'desired_version' => (int)$row['desired_version'],
                'desired_hash' => bin2hex((string)$row['desired_hash']),
            ];
        }
        $result->free();
        return $rows;
    }

    /** @return list<array{emission_id:string,desired_version:int,desired_hash:string}> */
    public function projectionStatesAfter(?string $afterUuid, int $limit = 200): array
    {
        $limit = min(max($limit, 1), 500);
        if ($afterUuid === null || $afterUuid === '') {
            $result = $this->db->query("SELECT aggregate_uuid, desired_version, desired_hash FROM sync_projection_state WHERE aggregate_type='CIF' ORDER BY aggregate_uuid LIMIT {$limit}");
        } else {
            $stmt = $this->db->prepare("SELECT aggregate_uuid, desired_version, desired_hash FROM sync_projection_state WHERE aggregate_type='CIF' AND aggregate_uuid>? ORDER BY aggregate_uuid LIMIT {$limit}");
            $stmt->bind_param('s', $afterUuid);
            $stmt->execute();
            $result = $stmt->get_result();
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'emission_id' => (string)$row['aggregate_uuid'],
                'desired_version' => (int)$row['desired_version'],
                'desired_hash' => bin2hex((string)$row['desired_hash']),
            ];
        }
        $result->free();
        if (isset($stmt)) {
            $stmt->close();
        }
        return $rows;
    }

    public function automaticReconciliationCursor(): ?string
    {
        $result = $this->db->query('SELECT auto_reconcile_cursor FROM sync_state WHERE id=1 LIMIT 1');
        $row = $result->fetch_assoc() ?: [];
        $result->free();
        $cursor = $row['auto_reconcile_cursor'] ?? null;
        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function setAutomaticReconciliationCursor(?string $cursor): void
    {
        $stmt = $this->db->prepare('UPDATE sync_state SET auto_reconcile_cursor=?, atualizado_em=UTC_TIMESTAMP(6) WHERE id=1');
        $stmt->bind_param('s', $cursor);
        $stmt->execute();
        $stmt->close();
    }

    public function acquireAutomationLock(string $name): bool
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, 0) AS acquired');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['acquired'] ?? 0) === 1;
    }

    public function releaseAutomationLock(string $name): void
    {
        $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->close();
    }

    public function recordAutomaticWorker(bool $success, ?string $error): void
    {
        $error = $error !== null ? substr($error, 0, 500) : null;
        if ($success) {
            $stmt = $this->db->prepare('UPDATE sync_state SET ultimo_worker_automatico_em=UTC_TIMESTAMP(6), ultimo_worker_automatico_ok_em=UTC_TIMESTAMP(6), ultimo_erro_automatico=NULL, atualizado_em=UTC_TIMESTAMP(6) WHERE id=1');
            $stmt->execute();
            $stmt->close();
            return;
        }
        $stmt = $this->db->prepare('UPDATE sync_state SET ultimo_worker_automatico_em=UTC_TIMESTAMP(6), ultimo_erro_automatico=?, atualizado_em=UTC_TIMESTAMP(6) WHERE id=1');
        $stmt->bind_param('s', $error);
        $stmt->execute();
        $stmt->close();
    }

    public function recordAutomaticReconciliation(bool $success, ?string $error): void
    {
        $error = $error !== null ? substr($error, 0, 500) : null;
        if ($success) {
            $stmt = $this->db->prepare('UPDATE sync_state SET ultima_reconciliacao_automatica_em=UTC_TIMESTAMP(6), ultimo_erro_automatico=NULL, atualizado_em=UTC_TIMESTAMP(6) WHERE id=1');
            $stmt->execute();
            $stmt->close();
            return;
        }
        $stmt = $this->db->prepare('UPDATE sync_state SET ultimo_erro_automatico=?, atualizado_em=UTC_TIMESTAMP(6) WHERE id=1');
        $stmt->bind_param('s', $error);
        $stmt->execute();
        $stmt->close();
    }

    public function startReconciliation(): array
    {
        $uuid = Uuid::v4();
        $status = 'RUNNING';
        $stmt = $this->db->prepare("INSERT INTO sync_reconciliation_runs (uuid,iniciado_em,status) VALUES (?,UTC_TIMESTAMP(6),?)");
        $stmt->bind_param('ss', $uuid, $status);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return ['id' => $id, 'uuid' => $uuid];
    }

    public function finishReconciliation(int $id, string $status, int $checked, int $divergences, int $reenqueued, int $errors, array $summary): void
    {
        $json = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare('UPDATE sync_reconciliation_runs SET finalizado_em=UTC_TIMESTAMP(6), status=?, registros_verificados=?, divergencias=?, reenfileirados=?, erros=?, resumo=? WHERE id=?');
        $stmt->bind_param('siiiisi', $status, $checked, $divergences, $reenqueued, $errors, $json, $id);
        $stmt->execute();
        $stmt->close();
        $this->db->query("UPDATE sync_state SET ultima_reconciliacao_em=UTC_TIMESTAMP(6), atualizado_em=UTC_TIMESTAMP(6) WHERE id=1");
    }


    public function updateRemoteInfo(?string $pluginVersion, ?string $schemaVersion): void
    {
        $stmt = $this->db->prepare('UPDATE sync_state SET remote_plugin_version=COALESCE(?,remote_plugin_version), remote_schema_version=COALESCE(?,remote_schema_version), atualizado_em=UTC_TIMESTAMP(6) WHERE id=1');
        $stmt->bind_param('ss', $pluginVersion, $schemaVersion);
        $stmt->execute();
        $stmt->close();
    }

    private function recordGlobalFailure(): void
    {
        $this->db->query("UPDATE sync_state SET ultima_tentativa_em=UTC_TIMESTAMP(6), falhas_consecutivas=falhas_consecutivas+1, atualizado_em=UTC_TIMESTAMP(6) WHERE id=1");
    }
}
