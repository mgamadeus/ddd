<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Forbidden: The Account has insufficient permissions to access endpoint
 */
class ForbiddenException extends Exception
{
    protected static int $defaultCode = Response::HTTP_FORBIDDEN;
}