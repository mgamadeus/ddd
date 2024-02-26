<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use DDD\Infrastructure\Validation\ValidationErrors;
use Symfony\Component\HttpFoundation\Response;

/**
 * BadRequest: The data provided does not match requirements
 */
class BadRequestException extends Exception
{
    protected static int $defaultCode = Response::HTTP_BAD_REQUEST;

    public ValidationErrors $validationErrors;
}