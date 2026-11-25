<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Audit;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use mysqli;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Support\RequestContext;
use SMTT\CIF\Gestao\Support\Uuid;

final class AuditLogger
{
    public function __construct(
        private readonly mysqli $db,
        private readonly TransactionManager $transactions
    ) {
    }

    /**
     * Registra auditoria append-only. A partir da migration de integridade, cada
     * novo registro é encadeado por SHA-256 ao registro anterior. Registros
     * históricos anteriores à ativação permanecem preservados como legado e são
     * cobertos por uma âncora criptográfica de transição.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function log(
        string $operation,
        string $entityType,
        ?int $entityId,
        ?string $entityUuid,
        ?CifUser $actor,
        string $origin,
        ?array $before = null,
        ?array $after = null,
        ?string $justification = null
    ): void {
        $uuid = Uuid::v4();
        $requestId = RequestContext::requestId();
        $beforeJson = $this->json($before);
        $afterJson = $this->json($after);

        $actorCifId = $actor?->id;
        $actorWpId = $actor?->wpUserId;
        $actorLogin = $actor?->login;
        $actorName = $actor?->name;

        if ($actor === null && (str_starts_with($origin, 'BOOTSTRAP') || str_starts_with($origin, 'WP_ADMIN')) && function_exists('wp_get_current_user')) {
            $wpUser = wp_get_current_user();
            if ($wpUser instanceof \WP_User && (int)$wpUser->ID > 0) {
                $actorWpId = (int)$wpUser->ID;
                $actorLogin = (string)$wpUser->user_login;
                $actorName = (string)$wpUser->display_name;
            }
        }

        $ip = $this->ipBinary();
        $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500)
            : null;
        $occurredAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $this->transactions->run(function () use (
            $uuid, $requestId, $beforeJson, $afterJson, $actorCifId, $actorWpId,
            $actorLogin, $actorName, $operation, $entityType, $entityId, $entityUuid,
            $justification, $ip, $userAgent, $origin, $occurredAt
        ): void {
            $anchor = $this->lockChainAnchor();
            if ($anchor === null) {
                // Compatibilidade durante uma atualização ainda sem migration 023.
                $this->insertLegacy(
                    $uuid, $occurredAt, $actorCifId, $actorWpId, $actorLogin, $actorName,
                    $operation, $entityType, $entityId, $entityUuid, $beforeJson, $afterJson,
                    $justification, $ip, $userAgent, $origin, $requestId
                );
                return;
            }

            $previousHash = (string)$anchor['last_hash'];
            $record = [
                'uuid' => $uuid,
                'ocorrido_em' => $occurredAt,
                'usuario_cif_id' => $actorCifId,
                'actor_wp_user_id' => $actorWpId,
                'actor_login' => $actorLogin,
                'actor_nome' => $actorName,
                'operacao' => $operation,
                'entidade_tipo' => $entityType,
                'entidade_id' => $entityId,
                'entidade_uuid' => $entityUuid,
                'valor_anterior' => $beforeJson,
                'valor_posterior' => $afterJson,
                'justificativa' => $justification,
                'ip_origem' => $ip,
                'user_agent' => $userAgent,
                'origem' => $origin,
                'request_id' => $requestId,
            ];
            $recordHash = AuditChain::hash($previousHash, $record);

            $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO auditoria (
    uuid, ocorrido_em, usuario_cif_id, actor_wp_user_id, actor_login, actor_nome,
    operacao, entidade_tipo, entidade_id, entidade_uuid, valor_anterior,
    valor_posterior, justificativa, ip_origem, user_agent, origem, request_id,
    previous_hash, record_hash
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
            $stmt->bind_param(
                'ssiissssissssssssss',
                $uuid,
                $occurredAt,
                $actorCifId,
                $actorWpId,
                $actorLogin,
                $actorName,
                $operation,
                $entityType,
                $entityId,
                $entityUuid,
                $beforeJson,
                $afterJson,
                $justification,
                $ip,
                $userAgent,
                $origin,
                $requestId,
                $previousHash,
                $recordHash
            );
            $stmt->execute();
            $auditId = (int)$stmt->insert_id;
            $stmt->close();

            $update = $this->db->prepare(
                'UPDATE auditoria_chain_anchor SET last_hash=?, last_audit_id=?, atualizado_em=UTC_TIMESTAMP(6) WHERE id=1'
            );
            $update->bind_param('si', $recordHash, $auditId);
            $update->execute();
            if ($update->affected_rows !== 1) {
                $update->close();
                throw new \RuntimeException('Não foi possível avançar a cadeia criptográfica de auditoria.');
            }
            $update->close();
        });
    }

    /** @return array<string,mixed>|null */
    private function lockChainAnchor(): ?array
    {
        try {
            $result = $this->db->query('SELECT * FROM auditoria_chain_anchor WHERE id=1 FOR UPDATE');
        } catch (\Throwable) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    }

    private function insertLegacy(
        string $uuid,
        string $occurredAt,
        ?int $actorCifId,
        ?int $actorWpId,
        ?string $actorLogin,
        ?string $actorName,
        string $operation,
        string $entityType,
        ?int $entityId,
        ?string $entityUuid,
        ?string $beforeJson,
        ?string $afterJson,
        ?string $justification,
        ?string $ip,
        ?string $userAgent,
        string $origin,
        ?string $requestId
    ): void {
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO auditoria (
    uuid, ocorrido_em, usuario_cif_id, actor_wp_user_id, actor_login, actor_nome,
    operacao, entidade_tipo, entidade_id, entidade_uuid, valor_anterior,
    valor_posterior, justificativa, ip_origem, user_agent, origem, request_id,
    previous_hash, record_hash
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
SQL);
        $stmt->bind_param(
            'ssiissssissssssss',
            $uuid,
            $occurredAt,
            $actorCifId,
            $actorWpId,
            $actorLogin,
            $actorName,
            $operation,
            $entityType,
            $entityId,
            $entityUuid,
            $beforeJson,
            $afterJson,
            $justification,
            $ip,
            $userAgent,
            $origin,
            $requestId
        );
        $stmt->execute();
        $stmt->close();
    }

    /** @param array<string,mixed>|null $value */
    private function json(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new \RuntimeException('Não foi possível serializar dados de auditoria.', 0, $e);
        }
    }

    private function ipBinary(): ?string
    {
        $raw = isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
        if ($raw === '') {
            return null;
        }
        $packed = @inet_pton($raw);
        return $packed === false ? null : $packed;
    }
}
