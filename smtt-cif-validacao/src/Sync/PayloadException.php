<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Sync;

use RuntimeException;

final class PayloadException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'INVALID_PAYLOAD', public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
