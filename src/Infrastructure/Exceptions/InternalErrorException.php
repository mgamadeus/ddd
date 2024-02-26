<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * InternalError: An internal error has occured
 */
class InternalErrorException extends Exception
{
    protected static int $defaultCode = Response::HTTP_INTERNAL_SERVER_ERROR;
}