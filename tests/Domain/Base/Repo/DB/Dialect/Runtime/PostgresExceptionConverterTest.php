<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Runtime;

use DDD\Domain\Base\Repo\DB\Dialect\Runtime\PostgresExceptionConverter;
use Doctrine\DBAL\Driver\API\PostgreSQL\ExceptionConverter as DbalPostgresExceptionConverter;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use PDOException;
use PHPUnit\Framework\TestCase;

/** SQLSTATE 55P03 answers the lock wait timeout class MariaDB's error 1205 answers; other states keep DBAL's mapping. */
class PostgresExceptionConverterTest extends TestCase
{
    protected function createDriverException(string $sqlState): PdoDriverException
    {
        $pdoException = new PDOException("SQLSTATE[$sqlState]: test");
        $pdoException->errorInfo = [$sqlState, 7, 'test'];
        return PdoDriverException::new($pdoException);
    }

    public function testLockNotAvailableBecomesALockWaitTimeout(): void
    {
        $converter = new PostgresExceptionConverter(new DbalPostgresExceptionConverter());

        $this->assertInstanceOf(LockWaitTimeoutException::class, $converter->convert($this->createDriverException('55P03'), null));
    }

    public function testOtherStatesKeepDbalsMapping(): void
    {
        $converter = new PostgresExceptionConverter(new DbalPostgresExceptionConverter());

        $this->assertInstanceOf(DeadlockException::class, $converter->convert($this->createDriverException('40P01'), null));
        $this->assertNotInstanceOf(LockWaitTimeoutException::class, $converter->convert($this->createDriverException('57014'), null));
    }
}
