<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Harness;

use DDD\Domain\Base\Repo\DB\Dialect\Runtime\AbortedTransactionException;
use DDD\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Exception as DbalException;
use Throwable;

/**
 * §4.7, proven against a real PostgreSQL 18 server (PostgreSQL-only: MariaDB has no equivalent aborted-transaction
 * state). A statement error inside a transaction leaves PostgreSQL itself in the 25P02 "current transaction is
 * aborted" state, where PostgreSQL's own `COMMIT` silently turns into a `ROLLBACK`; {@see
 * \DDD\Domain\Base\Repo\DB\Dialect\Runtime\AbortedTransactionGuard} makes that explicit instead.
 */
class AbortedTransactionGuardTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (static::currentDriver() !== 'pdo_pgsql') {
            $this->markTestSkipped('AbortedTransactionGuard is PostgreSQL-only (§4.7) — MariaDB has no 25P02 equivalent.');
        }
    }

    public function testAStatementErrorInsideATransactionMakesCommitThrowAndRollsBack(): void
    {
        $connection = static::connection();
        $connection->beginTransaction();

        try {
            // A deliberately invalid statement, real enough to abort the transaction server-side.
            $connection->executeStatement('SELECT 1 / 0');
            $this->fail('Expected the division-by-zero statement to throw.');
        } catch (DbalException) {
            // Expected — the transaction is now aborted server-side.
        }

        try {
            $connection->commit();
            $this->fail('Expected commit() on an aborted transaction to throw AbortedTransactionException.');
        } catch (AbortedTransactionException $exception) {
            $this->assertInstanceOf(Throwable::class, $exception->getPrevious());
        }

        $this->assertFalse($connection->isTransactionActive(), 'The guard must leave no open transaction behind.');
        // The connection must still be usable for a fresh statement/transaction afterward.
        $this->assertSame(1, (int)$connection->fetchOne('SELECT 1'));
    }

    public function testASuccessfulRollbackToSavepointClearsTheAbortedMark(): void
    {
        $connection = static::connection();
        $connection->beginTransaction();
        $connection->executeStatement('SAVEPOINT guard_test_sp');

        try {
            $connection->executeStatement('SELECT 1 / 0');
            $this->fail('Expected the division-by-zero statement to throw.');
        } catch (DbalException) {
            // Expected.
        }

        // PostgreSQL itself allows a ROLLBACK TO SAVEPOINT to recover from the aborted state; the guard must
        // recognise that success and clear its own mark so a later commit() is not refused for no reason.
        $connection->executeStatement('ROLLBACK TO SAVEPOINT guard_test_sp');
        $connection->commit();

        $this->assertFalse($connection->isTransactionActive());
    }
}
