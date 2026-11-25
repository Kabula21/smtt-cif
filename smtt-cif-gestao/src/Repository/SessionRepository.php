<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use mysqli;
use SMTT\CIF\Gestao\Support\Uuid;

final class SessionRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function create(int $userId, string $tokenHash, string $expiresAt, ?string $ipBinary, ?string $userAgentHash): void
    {
        $uuid = Uuid::v4();
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO sessoes_cif
(uuid,usuario_id,token_hash,criada_em,ultimo_acesso_em,expira_em,ip_origem,user_agent_hash)
VALUES(?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),?,?,?)
SQL);
        $stmt->bind_param('sissss', $uuid, $userId, $tokenHash, $expiresAt, $ipBinary, $userAgentHash);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array{user_id:int,expires_at:string,last_seen:string}|null */
    public function activeByHash(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT s.usuario_id,s.expira_em,s.ultimo_acesso_em
FROM sessoes_cif s
JOIN usuarios_cif u ON u.id=s.usuario_id
WHERE s.token_hash=? AND s.revogada_em IS NULL AND s.expira_em>UTC_TIMESTAMP(6)
  AND u.ativo=1 AND u.removido_em IS NULL
LIMIT 1
SQL);
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        return [
            'user_id' => (int)$row['usuario_id'],
            'expires_at' => (string)$row['expira_em'],
            'last_seen' => (string)$row['ultimo_acesso_em'],
        ];
    }

    public function touch(string $tokenHash): void
    {
        $stmt = $this->db->prepare('UPDATE sessoes_cif SET ultimo_acesso_em=UTC_TIMESTAMP(6) WHERE token_hash=? AND revogada_em IS NULL');
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $stmt->close();
    }

    public function revokeByHash(string $tokenHash): void
    {
        $stmt = $this->db->prepare('UPDATE sessoes_cif SET revogada_em=COALESCE(revogada_em,UTC_TIMESTAMP(6)) WHERE token_hash=?');
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $stmt->close();
    }

    public function revokeUser(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE sessoes_cif SET revogada_em=COALESCE(revogada_em,UTC_TIMESTAMP(6)) WHERE usuario_id=? AND revogada_em IS NULL');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function purgeExpired(): void
    {
        $this->db->query("DELETE FROM sessoes_cif WHERE expira_em<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY) OR revogada_em<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 DAY)");
    }
}
