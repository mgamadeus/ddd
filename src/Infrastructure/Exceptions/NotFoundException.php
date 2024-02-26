<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * NotFound: The item does either not exist or current Account has no access to it
 */
class NotFoundException extends Exception
{
    protected static int $defaultCode = Response::HTTP_NOT_FOUND;
}