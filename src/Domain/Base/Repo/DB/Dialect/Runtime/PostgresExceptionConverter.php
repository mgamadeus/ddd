<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Query;

/**
 * PostgreSQL exception conversion with the SQLSTATEs DBAL leaves generic: `55P03` (lock_not_available: `lock_timeout`
 * reached, `FOR UPDATE NOWAIT`) becomes {@see LockWaitTimeoutException}, as MariaDB's error 1205 already does, so a
 * caller catches one class on both engines. Everything else goes to DBAL's own converter.
 */
class PostgresExceptionConverter implements ExceptionConverter
{
    public const string SQLSTATE_LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(protected ExceptionConverter $wrappedExceptionConverter)
    {
    }

    public function convert(Exception $exception, ?Query $query): DriverException
    {
        if ($exception->getSQLState() === self::SQLSTATE_LOCK_NOT_AVAILABLE) {
            return new LockWaitTimeoutException($exception, $query);
        }
        return $this->wrappedExceptionConverter->convert($exception, $query);
    }
}
