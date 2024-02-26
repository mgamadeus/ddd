<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Method not allowed: Method is valid, but resource does not support it.
 * Happens e.g. when you try to access a feature that is not available to the client
 */
class MethodNotAllowedException extends Exception
{
    protected static int $defaultCode = Response::HTTP_METHOD_NOT_ALLOWED;
}