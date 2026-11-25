<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Sync;

final class RetryPolicy
{
    public const MAX_AUTOMATIC_ATTEMPTS = 10;

    public function delaySeconds(int $attempt): int
    {
        $schedule = [60, 300, 900, 1800, 3600, 10800, 21600];
        $base = $schedule[min(max($attempt, 1) - 1, count($schedule) - 1)];
        $jitter = random_int(0, max(5, (int) floor($base * 0.10)));
        return $base + $jitter;
    }

    public function isTransientHttpStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    public function isPermanentHttpStatus(int $status): bool
    {
        return in_array($status, [400, 401, 403, 404, 409, 410, 415, 422], true);
    }
}
