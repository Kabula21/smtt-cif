<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Clock
{
    public static function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
