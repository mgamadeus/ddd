<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Unauthorized: The given authentication credentials are either missing or wrong
 */
class UnauthorizedException extends Exception
{
    protected static int $defaultCode = Response::HTTP_UNAUTHORIZED;
}