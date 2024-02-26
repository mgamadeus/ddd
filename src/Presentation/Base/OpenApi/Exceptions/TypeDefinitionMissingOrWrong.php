<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Exceptions;

use DDD\Infrastructure\Exceptions\Exception;
use Symfony\Component\HttpFoundation\Response;

class TypeDefinitionMissingOrWrong extends Exception
{
    protected static int $defaultCode = Response::HTTP_INTERNAL_SERVER_ERROR;
}