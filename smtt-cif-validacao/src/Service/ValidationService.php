<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Service;

use SMTT\CIF\Validacao\Database\TransactionManager;
use SMTT\CIF\Validacao\Repository\PublicIssuanceRepository;
use SMTT\CIF\Validacao\Repository\RateLimitRepository;
use SMTT\CIF\Validacao\Security\Base64Url;

final class ValidationService
{
    public function __construct(
        private readonly PublicIssuanceRepository $issuances,
        private readonly RateLimitRepository $rateLimits,
        private readonly TransactionManager $transactions
    ) {
    }

    /** @return array{result:string,record:?array<string,mixed>} */
    public function validate(string $token, string $clientIp): array
    {
        $clientHash = $this->clientHash($clientIp);
        if ($this->rateLimits->isBlocked($clientHash)) {
            return ['result' => 'RATE_LIMITED', 'record' => null];
        }

        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return $this->invalidAttempt($clientHash);
        }

        try {
            $rawToken = Base64Url::decode($token);
        } catch (\InvalidArgumentException) {
            return $this->invalidAttempt($clientHash);
        }

        if (strlen($rawToken) !== 32) {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($rawToken);
            }
            return $this->invalidAttempt($clientHash);
        }

        if (function_exists('sodium_memzero')) {
            sodium_memzero($rawToken);
        }

        $tokenHash = hash('sha256', $token, true);
        $record = $this->issuances->findByTokenHash($tokenHash);
        if (function_exists('sodium_memzero')) {
            sodium_memzero($token);
        }

        if ($record === null) {
            return $this->invalidAttempt($clientHash);
        }

        // A leitura válida não consome a cota de tentativas. O token tem 256 bits
        // e funciona como segredo de posse do QR; o rate limit protege somente
        // tentativas inválidas/automatizadas para não penalizar consultas legítimas.
        return [
            'result' => (string)$record['public_status'] === 'VALIDA' ? 'VALID' : 'NOT_VALID',
            'record' => $record,
        ];
    }

    public function cleanupRateLimits(): void
    {
        $this->rateLimits->cleanup();
    }

    /** @return array{result:string,record:null} */
    private function invalidAttempt(string $clientHash): array
    {
        $allowed = $this->transactions->run(fn(): bool => $this->rateLimits->consume($clientHash));
        return ['result' => $allowed ? 'NOT_FOUND' : 'RATE_LIMITED', 'record' => null];
    }

    private function clientHash(string $ip): string
    {
        $ip = filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
        $salt = function_exists('wp_salt') ? wp_salt('auth') : 'smtt-cif-public';
        return hash_hmac('sha256', $ip, $salt, true);
    }
}
