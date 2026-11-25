<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Security;

use DateTimeImmutable;
use DateTimeZone;
use SMTT\CIF\Gestao\Repository\SessionRepository;

final class SessionManager
{
    private const COOKIE = 'smtt_cif_session';
    private const NORMAL_LIFETIME = 28800; // 8 horas
    private const REMEMBER_LIFETIME = 604800; // 7 dias

    public function __construct(private readonly SessionRepository $repository)
    {
    }

    public function start(int $userId, bool $remember): void
    {
        $this->revokeCurrent();
        $raw = random_bytes(32);
        $token = $this->base64Url($raw);
        $hash = hash('sha256', $raw, true);
        $lifetime = $remember ? self::REMEMBER_LIFETIME : self::NORMAL_LIFETIME;
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $lifetime . ' seconds')
            ->format('Y-m-d H:i:s.u');

        $this->repository->create(
            $userId,
            $hash,
            $expiresAt,
            $this->ipBinary(),
            $this->userAgentHash()
        );

        $this->setCookie($token, $remember ? time() + $lifetime : 0);
    }

    public function userId(): ?int
    {
        $hash = $this->currentHash();
        if ($hash === null) {
            return null;
        }
        $row = $this->repository->activeByHash($hash);
        if ($row === null) {
            $this->clearCookie();
            return null;
        }

        $last = strtotime($row['last_seen'] . ' UTC');
        if ($last === false || $last < time() - 300) {
            $this->repository->touch($hash);
        }
        return $row['user_id'];
    }

    public function logout(): void
    {
        $this->revokeCurrent();
        $this->clearCookie();
    }

    public function revokeUser(int $userId): void
    {
        $this->repository->revokeUser($userId);
    }

    public function revokeCurrent(): void
    {
        $hash = $this->currentHash();
        if ($hash !== null) {
            $this->repository->revokeByHash($hash);
        }
    }

    public function purgeExpired(): void
    {
        $this->repository->purgeExpired();
    }

    private function currentHash(): ?string
    {
        $token = isset($_COOKIE[self::COOKIE]) ? trim((string)$_COOKIE[self::COOKIE]) : '';
        if ($token === '') {
            return null;
        }
        $raw = $this->base64UrlDecode($token);
        if ($raw === null || strlen($raw) !== 32) {
            $this->clearCookie();
            return null;
        }
        return hash('sha256', $raw, true);
    }

    private function setCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => $this->cookiePath(),
            'secure' => function_exists('is_ssl') ? is_ssl() : false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $value;
    }

    private function clearCookie(): void
    {
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', [
                'expires' => time() - 3600,
                'path' => $this->cookiePath(),
                'secure' => function_exists('is_ssl') ? is_ssl() : false,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        unset($_COOKIE[self::COOKIE]);
    }

    private function cookiePath(): string
    {
        $path = function_exists('wp_parse_url') ? wp_parse_url(home_url('/cif'), PHP_URL_PATH) : '/cif';
        return is_string($path) && $path !== '' ? rtrim($path, '/') : '/cif';
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

    private function userAgentHash(): ?string
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string)$_SERVER['HTTP_USER_AGENT']) : '';
        return $ua === '' ? null : hash('sha256', $ua, true);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
