<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Repository;

use DateTimeImmutable;
use DateTimeZone;
use mysqli;

final class RateLimitRepository
{
    public const WINDOW_SECONDS = 900;
    public const MAX_ATTEMPTS = 12;

    public function __construct(private readonly mysqli $db)
    {
    }

    public function isBlocked(string $clientHash): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stmt = $this->db->prepare('SELECT blocked_until FROM validation_rate_limits WHERE client_hash=? LIMIT 1');
        $stmt->bind_param('s', $clientHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!is_array($row) || empty($row['blocked_until'])) {
            return false;
        }

        try {
            return new DateTimeImmutable((string)$row['blocked_until'], new DateTimeZone('UTC')) > $now;
        } catch (\Throwable) {
            return false;
        }
    }

    public function consume(string $clientHash): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stmt = $this->db->prepare('SELECT window_started_at,attempts,blocked_until FROM validation_rate_limits WHERE client_hash=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $clientHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!is_array($row)) {
            $start = $now->format('Y-m-d H:i:s');
            $attempts = 1;
            $stmt = $this->db->prepare('INSERT INTO validation_rate_limits(client_hash,window_started_at,attempts,blocked_until,updated_at) VALUES(?,?,?,NULL,UTC_TIMESTAMP(6))');
            $stmt->bind_param('ssi', $clientHash, $start, $attempts);
            $stmt->execute();
            $stmt->close();
            return true;
        }

        $blockedUntil = !empty($row['blocked_until']) ? new DateTimeImmutable((string)$row['blocked_until'], new DateTimeZone('UTC')) : null;
        if ($blockedUntil !== null && $blockedUntil > $now) {
            return false;
        }

        $window = new DateTimeImmutable((string)$row['window_started_at'], new DateTimeZone('UTC'));
        if (($now->getTimestamp() - $window->getTimestamp()) >= self::WINDOW_SECONDS) {
            $start = $now->format('Y-m-d H:i:s');
            $attempts = 1;
            $stmt = $this->db->prepare('UPDATE validation_rate_limits SET window_started_at=?,attempts=?,blocked_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE client_hash=?');
            $stmt->bind_param('sis', $start, $attempts, $clientHash);
            $stmt->execute();
            $stmt->close();
            return true;
        }

        $attempts = (int)$row['attempts'] + 1;
        $blocked = null;
        if ($attempts > self::MAX_ATTEMPTS) {
            $blocked = $now->modify('+' . self::WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        }
        $stmt = $this->db->prepare('UPDATE validation_rate_limits SET attempts=?,blocked_until=?,updated_at=UTC_TIMESTAMP(6) WHERE client_hash=?');
        $stmt->bind_param('iss', $attempts, $blocked, $clientHash);
        $stmt->execute();
        $stmt->close();
        return $blocked === null;
    }

    public function cleanup(): void
    {
        $this->db->query('DELETE FROM validation_rate_limits WHERE updated_at < UTC_TIMESTAMP(6) - INTERVAL 2 DAY');
    }
}
