<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Support;

final class RequestContext
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        return self::$requestId ??= Uuid::v4();
    }

    private function __construct()
    {
    }
}
