<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/** @see PostgresExceptionConverterMiddleware */
class PostgresExceptionConverterDriver extends AbstractDriverMiddleware
{
    public function getExceptionConverter(): ExceptionConverter
    {
        return new PostgresExceptionConverter(parent::getExceptionConverter());
    }
}
