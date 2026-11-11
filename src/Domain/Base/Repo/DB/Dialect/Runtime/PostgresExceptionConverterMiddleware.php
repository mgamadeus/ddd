<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Hands DBAL the {@see PostgresExceptionConverter}, so a PostgreSQL error answers the same DBAL exception class as
 * the matching MariaDB error. Registered only by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 */
class PostgresExceptionConverterMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new PostgresExceptionConverterDriver($driver);
    }
}
