<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Security;

use RuntimeException;

final class AuthenticationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 401, public readonly string $errorCode = 'AUTHENTICATION_FAILED')
    {
        parent::__construct($message);
    }
}
