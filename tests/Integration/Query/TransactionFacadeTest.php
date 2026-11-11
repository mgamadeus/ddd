<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\TransactionIsolationLevel;

/**
 * The transaction helpers of the dialect facade behave the same on both engines: the insert that skips existing
 * rows, the lock bound to a transaction, the transaction timeouts, the distinct string aggregate and the share lock.
 * A second connection plays the other process.
 */
class TransactionFacadeTest extends IntegrationTestCase
{
    protected const string TABLE = 'TransactionFacadeRows';

    protected const string SOURCE_TABLE = 'TransactionFacadeSourceRows';

    /** @var Connection[] opened by {@see secondConnection()}, closed in tearDown() */
    protected array $openedConnections = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        $dialect = static::dialect();
        foreach ([static::TABLE, static::SOURCE_TABLE] as $table) {
            static::connection()->executeStatement('DROP TABLE IF EXISTS ' . $dialect->quote($table));
            static::connection()->executeStatement(
                'CREATE TABLE ' . $dialect->quote($table) . ' (' . $dialect->quote('id') . ' INT PRIMARY KEY, '
                . $dialect->quote('code') . ' VARCHAR(20) NOT NULL UNIQUE, ' . $dialect->quote('label') . ' VARCHAR(5) NOT NULL)'
            );
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            foreach ([static::TABLE, static::SOURCE_TABLE] as $table) {
                static::connection()->executeStatement('DROP TABLE IF EXISTS ' . static::dialect()->quote($table));
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([static::TABLE, static::SOURCE_TABLE] as $table) {
            static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote($table));
        }
        static::insertRow(static::TABLE, ['id' => 1, 'code' => 'A', 'label' => 'old']);
    }

    protected function tearDown(): void
    {
        while (static::connection()->isTransactionActive()) {
            static::connection()->rollBack();
        }
        foreach ($this->openedConnections as $connection) {
            $connection->close();
        }
        $this->openedConnections = [];
        parent::tearDown();
    }

    /** A second, independent connection to the same database: the other process. */
    protected function secondConnection(): Connection
    {
        $connection = DriverManager::getConnection(static::dialect()->connectionParams([
            'driver' => (string)Config::getEnv('DB_DEFAULT_CONNECTION_DRIVER'),
            'host' => (string)Config::getEnv('DB_DEFAULT_CONNECTION_HOST'),
            'port' => (int)Config::getEnv('DB_DEFAULT_CONNECTION_HOST_PORT'),
            'user' => (string)Config::getEnv('DB_DEFAULT_CONNECTION_USER'),
            'password' => (string)Config::getEnv('DB_DEFAULT_CONNECTION_PASSWORD'),
            'dbname' => (string)Config::getEnv('DB_DEFAULT_CONNECTION_DB_NAME'),
        ]));
        $this->openedConnections[] = $connection;
        return $connection;
    }

    protected function lockName(string $suffix): string
    {
        return 'ddd_it_xact_lock_' . $suffix . '_' . bin2hex(random_bytes(4));
    }

    protected function columnList(): string
    {
        $dialect = static::dialect();
        return $dialect->quote('id') . ', ' . $dialect->quote('code') . ', ' . $dialect->quote('label');
    }

    /** @return string[] the codes of the target table, in id order */
    protected function codesWithLabels(): array
    {
        $dialect = static::dialect();
        return static::connection()->fetchFirstColumn(
            'SELECT ' . $dialect->quote('code') . " || ':' || " . $dialect->quote('label') . ' FROM '
            . $dialect->quote(static::TABLE) . ' ORDER BY ' . $dialect->quote('id')
        );
    }

    public function testMultiRowInsertSkipsExistingRowsAndCountsTheWrittenOnes(): void
    {
        $dialect = static::dialect();
        $insertSql = $dialect->insertSkippingExistingRows(
            'INSERT INTO ' . $dialect->quote(static::TABLE) . ' (' . $this->columnList() . ") VALUES (10, 'A', 'new'), (11, 'B', 'new'), (12, 'C', 'new')",
            static::TABLE,
            'code'
        );

        $this->assertSame(2, (int)static::connection()->executeStatement($insertSql));
        $this->assertSame(0, (int)static::connection()->executeStatement($insertSql));

        $codes = static::connection()->fetchFirstColumn(
            'SELECT ' . $dialect->quote('code') . ' FROM ' . $dialect->quote(static::TABLE) . ' ORDER BY ' . $dialect->quote('code')
        );
        $this->assertSame(['A', 'B', 'C'], $codes);
        $this->assertSame(
            'old',
            static::connection()->fetchOne(
                'SELECT ' . $dialect->quote('label') . ' FROM ' . $dialect->quote(static::TABLE) . ' WHERE ' . $dialect->quote('code') . " = 'A'"
            )
        );
    }

    /** The source table has the same column names as the target: the statement stays unambiguous. */
    public function testInsertSelectSkipsExistingRows(): void
    {
        $dialect = static::dialect();
        static::insertRow(static::SOURCE_TABLE, ['id' => 20, 'code' => 'A', 'label' => 'src']);
        static::insertRow(static::SOURCE_TABLE, ['id' => 21, 'code' => 'D', 'label' => 'src']);

        $insertSql = $dialect->insertSkippingExistingRows(
            'INSERT INTO ' . $dialect->quote(static::TABLE) . ' (' . $this->columnList() . ') SELECT ' . $this->columnList()
            . ' FROM ' . $dialect->quote(static::SOURCE_TABLE),
            static::TABLE,
            'code'
        );

        $this->assertSame(1, (int)static::connection()->executeStatement($insertSql));
        $this->assertSame(0, (int)static::connection()->executeStatement($insertSql));
    }

    /** Only the existing key is skipped: any other error of the insert still fails (unlike INSERT IGNORE). */
    public function testInsertSkippingExistingRowsStillFailsOnOtherErrors(): void
    {
        $dialect = static::dialect();
        $insertSql = $dialect->insertSkippingExistingRows(
            'INSERT INTO ' . $dialect->quote(static::TABLE) . ' (' . $this->columnList() . ") VALUES (30, 'E', 'far too long')",
            static::TABLE,
            'code'
        );

        $this->expectException(DbalException::class);
        static::connection()->executeStatement($insertSql);
    }

    public function testTransactionLockKeepsASecondTransactionOutUntilTheEnd(): void
    {
        $dialect = static::dialect();
        $name = $this->lockName('held');
        $holder = static::connection();
        $contender = $this->secondConnection();

        $holder->beginTransaction();
        $this->assertTrue($dialect->acquireTransactionLock($holder, $name, 2));

        $contender->beginTransaction();
        $startedAt = microtime(true);
        $this->assertFalse($dialect->acquireTransactionLock($contender, $name, 1));
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $startedAt);

        $holder->commit();
        $dialect->releaseTransactionLock($holder, $name);

        $this->assertTrue($dialect->acquireTransactionLock($contender, $name, 2));
        $contender->rollBack();
        $dialect->releaseTransactionLock($contender, $name);

        $holder->beginTransaction();
        $this->assertTrue($dialect->acquireTransactionLock($holder, $name, 1));
        $holder->rollBack();
        $dialect->releaseTransactionLock($holder, $name);
    }

    /** One name space with the named locks of locks(): a transaction lock keeps a named lock of that name out. */
    public function testTransactionLockSharesTheNameSpaceOfNamedLocks(): void
    {
        $dialect = static::dialect();
        $name = $this->lockName('shared');
        $holder = static::connection();
        $contender = $this->secondConnection();

        $holder->beginTransaction();
        $this->assertTrue($dialect->acquireTransactionLock($holder, $name, 1));

        $namedLockFunction = static::currentDriver() === 'pdo_pgsql'
            ? 'SELECT CASE WHEN pg_try_advisory_lock(' . crc32($name) . ') THEN 1 ELSE 0 END'
            : "SELECT GET_LOCK('$name', 0)";
        $this->assertSame(0, (int)$contender->fetchOne($namedLockFunction));

        $holder->rollBack();
        $dialect->releaseTransactionLock($holder, $name);
        $this->assertSame(1, (int)$contender->fetchOne($namedLockFunction));
    }

    public function testTransactionLockNeedsAnOpenTransaction(): void
    {
        $this->expectException(InternalErrorException::class);
        static::dialect()->acquireTransactionLock(static::connection(), $this->lockName('none'), 0);
    }

    /**
     * The level holds for the one transaction: a READ COMMITTED transaction sees another connection's commit between
     * two reads, a REPEATABLE READ one does not. Each engine is tested with the level that is NOT its session default
     * (MariaDB: REPEATABLE READ, PostgreSQL: READ COMMITTED); the next plain transaction is back at the default and
     * the session's own setting is unchanged.
     */
    public function testATransactionAtAnIsolationLevelKeepsItForThatTransactionOnly(): void
    {
        $isPostgres = static::currentDriver() === 'pdo_pgsql';
        $connection = static::connection();
        $other = $this->secondConnection();
        $sessionLevelSql = $isPostgres ? 'SHOW default_transaction_isolation' : 'SELECT @@session.tx_isolation';
        $sessionLevelBefore = (string)$connection->fetchOne($sessionLevelSql);
        $levelOtherThanDefault = $isPostgres ? TransactionIsolationLevel::REPEATABLE_READ : TransactionIsolationLevel::READ_COMMITTED;
        $seesOtherCommitAtThatLevel = !$isPostgres;

        static::dialect()->beginTransactionAtIsolationLevel($connection, $levelOtherThanDefault);
        $this->assertSame($seesOtherCommitAtThatLevel, $this->secondReadSeesACommitOfTheOtherConnection($connection, $other, 'first'));
        $connection->commit();

        $connection->beginTransaction();
        $this->assertSame(!$seesOtherCommitAtThatLevel, $this->secondReadSeesACommitOfTheOtherConnection($connection, $other, 'secnd'));
        $connection->commit();

        $this->assertSame($sessionLevelBefore, (string)$connection->fetchOne($sessionLevelSql));
    }

    public function testATransactionAtAnIsolationLevelNeedsAConnectionWithoutAnOpenTransaction(): void
    {
        static::connection()->beginTransaction();

        $this->expectException(InternalErrorException::class);
        static::dialect()->beginTransactionAtIsolationLevel(static::connection(), TransactionIsolationLevel::READ_COMMITTED);
    }

    /** Reads the label of row 1, lets $other change and commit it, reads again: whether the second read sees it */
    protected function secondReadSeesACommitOfTheOtherConnection(Connection $connection, Connection $other, string $newLabel): bool
    {
        $dialect = static::dialect();
        $selectSql = 'SELECT ' . $dialect->quote('label') . ' FROM ' . $dialect->quote(static::TABLE) . ' WHERE '
            . $dialect->quote('id') . ' = 1';
        $connection->fetchOne($selectSql);
        $other->executeStatement(
            'UPDATE ' . $dialect->quote(static::TABLE) . ' SET ' . $dialect->quote('label') . ' = ? WHERE ' . $dialect->quote('id') . ' = 1',
            [$newLabel]
        );
        return $connection->fetchOne($selectSql) === $newLabel;
    }

    public function testTransactionTimeoutsNeedAnOpenTransaction(): void
    {
        $this->expectException(InternalErrorException::class);
        static::dialect()->setTransactionTimeouts(static::connection(), 1, null);
    }

    /** A row another transaction holds: the waiting statement gives up after the lock wait timeout. */
    public function testLockWaitTimeoutEndsAStatementThatWaitsForARowLock(): void
    {
        $dialect = static::dialect();
        // the waiting side is the application's connection: its exception converter is the one the dialect configures
        $holder = $this->secondConnection();
        $contender = static::connection();
        $updateSql = 'UPDATE ' . $dialect->quote(static::TABLE) . ' SET ' . $dialect->quote('label') . " = 'x' WHERE "
            . $dialect->quote('id') . ' = 1';

        $holder->beginTransaction();
        $holder->executeStatement($updateSql);

        $contender->beginTransaction();
        $dialect->setTransactionTimeouts($contender, 1, null);
        $startedAt = microtime(true);
        $failed = false;
        try {
            $contender->executeStatement($updateSql);
        } catch (LockWaitTimeoutException) {
            // the same class on both engines: error 1205 on MariaDB, SQLSTATE 55P03 on PostgreSQL
            $failed = true;
        } finally {
            $contender->rollBack();
            $dialect->clearTransactionTimeouts($contender);
            $holder->rollBack();
        }

        $this->assertTrue($failed);
        $this->assertLessThan(5.0, microtime(true) - $startedAt);
    }

    /** After the transaction and clearTransactionTimeouts() the connection waits as long as the server default again. */
    public function testTimeoutsEndWithTheTransaction(): void
    {
        $dialect = static::dialect();
        $connection = $this->secondConnection();
        $readLimitsSql = static::currentDriver() === 'pdo_pgsql'
            ? "SELECT current_setting('lock_timeout') || '/' || current_setting('statement_timeout')"
            : "SELECT CONCAT(@@SESSION.innodb_lock_wait_timeout, '/', @@SESSION.max_statement_time)";
        $limitsBefore = $connection->fetchOne($readLimitsSql);

        $connection->beginTransaction();
        $dialect->setTransactionTimeouts($connection, 3, 7);
        $this->assertNotSame($limitsBefore, $connection->fetchOne($readLimitsSql));
        $connection->commit();
        $dialect->clearTransactionTimeouts($connection);

        $this->assertSame($limitsBefore, $connection->fetchOne($readLimitsSql));
    }

    public function testStringAggDistinctListsEachValueOnceInOrder(): void
    {
        $dialect = static::dialect();
        static::insertRow(static::SOURCE_TABLE, ['id' => 1, 'code' => 'x1', 'label' => 'b']);
        static::insertRow(static::SOURCE_TABLE, ['id' => 2, 'code' => 'x2', 'label' => 'a']);
        static::insertRow(static::SOURCE_TABLE, ['id' => 3, 'code' => 'x3', 'label' => 'b']);

        $this->assertSame(
            'a, b',
            static::connection()->fetchOne(
                'SELECT ' . $dialect->stringAggDistinct($dialect->quote('label'), ', ') . ' FROM ' . $dialect->quote(static::SOURCE_TABLE)
            )
        );
        $this->assertSame(
            '1|2|3',
            static::connection()->fetchOne(
                'SELECT ' . $dialect->stringAggDistinct($dialect->quote('id'), '|') . ' FROM ' . $dialect->quote(static::SOURCE_TABLE)
            )
        );
    }

    /** A share-locked row can be read and share-locked by another transaction, not changed. */
    public function testForShareLetsOthersReadAndKeepsThemFromChanging(): void
    {
        $dialect = static::dialect();
        $holder = static::connection();
        $contender = $this->secondConnection();
        $selectSql = 'SELECT ' . $dialect->quote('label') . ' FROM ' . $dialect->quote(static::TABLE) . ' WHERE '
            . $dialect->quote('id') . ' = 1 ' . $dialect->forShare();

        $holder->beginTransaction();
        $this->assertSame('old', $holder->fetchOne($selectSql));

        $contender->beginTransaction();
        $this->assertSame('old', $contender->fetchOne($selectSql));
        $contender->rollBack();

        $contender->beginTransaction();
        $dialect->setTransactionTimeouts($contender, 1, null);
        $failed = false;
        try {
            $contender->executeStatement(
                'UPDATE ' . $dialect->quote(static::TABLE) . ' SET ' . $dialect->quote('label') . " = 'x' WHERE " . $dialect->quote('id') . ' = 1'
            );
        } catch (DbalException) {
            $failed = true;
        } finally {
            $contender->rollBack();
            $dialect->clearTransactionTimeouts($contender);
            $holder->rollBack();
        }
        $this->assertTrue($failed);
    }
}
