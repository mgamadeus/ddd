<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Locks;

use DDD\Domain\Base\Repo\DB\Dialect\Locks\LockTimeoutException;
use DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Services\DatabaseSchemaDiffService;
use DDD\Infrastructure\Libs\Config;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `LockService` against a real server on both engines (architecture plan §4.12, D3; §6 build step 8). Run against
 * this build step's OWN isolated containers (never the shared `tests/Integration/docker-compose.yml` "integration"
 * project another agent may be using at the same time):
 *
 *   DDD_TEST_DRIVER=pdo_mysql DDD_TEST_DB_PORT=3327 vendor/bin/phpunit -c phpunit.integration.xml tests/Integration/Locks
 *   DDD_TEST_DRIVER=pdo_pgsql DDD_TEST_PG_PORT=5443 vendor/bin/phpunit -c phpunit.integration.xml tests/Integration/Locks
 *
 * Not an {@see \DDD\Tests\Integration\IntegrationTestCase} subclass — like {@see
 * \DDD\Tests\Integration\Harness\ConnectionBootTest}, locks need no fixture tables at all, and this class opens a
 * SECOND, independent connection per test to simulate a second process contending for the same name — reusing the
 * fixture base's single shared connection would not prove mutual exclusion across sessions.
 */
class LockServiceTest extends TestCase
{
    /** @var Connection[] opened by {@see secondConnection()}, closed in tearDown() */
    protected array $openedConnections = [];

    protected function setUp(): void
    {
        $driver = getenv('DDD_TEST_DRIVER') ?: ($_SERVER['DDD_TEST_DRIVER'] ?? null);
        if (!in_array($driver, ['pdo_mysql', 'pdo_pgsql'], true)) {
            $this->markTestSkipped(
                'Integration suite: start this build step\'s own docker compose and set DDD_TEST_DRIVER=pdo_mysql|pdo_pgsql'
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->openedConnections as $connection) {
            $connection->close();
        }
        $this->openedConnections = [];
    }

    protected function lockName(string $suffix): string
    {
        // Unique per test so a slow previous test's lock (e.g. a timeout test's 1s wait) can never bleed into the
        // next one on a shared server.
        return 'ddd_it_lock_' . $suffix . '_' . bin2hex(random_bytes(4));
    }

    /** A second, independent DBAL connection to the same scope — simulates a second process/session. */
    protected function secondConnection(): Connection
    {
        $dialect = EntityManagerFactory::getDialect();
        $params = $dialect->connectionParams([
            'driver' => (string)Config::getEnv('DB_DEFAULT_CONNECTION_DRIVER'),
            'host' => (string)Config::getEnv('DB_DEFAULT_CONNECTION_HOST'),
            'port' => (int)Config::getEnv('DB_DEFAULT_CONNECTION_HOST_PORT'),
            'user' => (string)Config::getEnv('DB_DEFAULT_CONNECTION_USER'),
            'password' => (string)Config::getEnv('DB_DEFAULT_CONNECTION_PASSWORD'),
            'dbname' => (string)Config::getEnv('DB_DEFAULT_CONNECTION_DB_NAME'),
        ]);
        $connection = DriverManager::getConnection($params);
        $this->openedConnections[] = $connection;
        return $connection;
    }

    /**
     * The dialect a "contender" (simulated second process) acquires through. On MariaDB, `locks($connection)`
     * always wraps the GIVEN connection in a fresh `MariaDbLockService` (architecture plan §4.12) — the shared
     * `EntityManagerFactory::getDialect()` singleton already behaves like a second process once handed a second
     * connection. On PostgreSQL, `locks()` now caches one `PostgresLockService` per scope ON the dialect instance
     * (§4.12 fix, this build step) so that NESTED acquires within the SAME process/request — two different call
     * sites each doing `getDialect()->locks($connection)` for a name this same request already holds — share one
     * depth map instead of each opening its own direct connection and blocking on the other's held advisory lock.
     * That means handing the shared singleton a second `$connection` no longer simulates a second process on
     * PostgreSQL (it would return the SAME cached instance as the holder's, defeating the simulation) — a
     * genuinely different process gets its OWN `PostgresDialect` (statics do not cross OS processes), so the
     * test constructs a fresh one here to match.
     */
    protected function contenderDialect(): SqlDialect
    {
        $dialect = EntityManagerFactory::getDialect();
        return $dialect instanceof PostgresDialect ? new PostgresDialect() : $dialect;
    }

    public function testAcquireThenReleaseRoundTrip(): void
    {
        $name = $this->lockName('roundtrip');
        $dialect = EntityManagerFactory::getDialect();
        $lock = $dialect->locks(EntityManagerFactory::getInstance()->getConnection());

        $this->assertTrue($lock->acquire($name, 5));
        $this->assertTrue($lock->release($name));
    }

    public function testASecondSessionIsBlockedAndTimesOut(): void
    {
        $name = $this->lockName('blocked');
        $dialect = EntityManagerFactory::getDialect();

        $holder = $dialect->locks(EntityManagerFactory::getInstance()->getConnection());
        $this->assertTrue($holder->acquire($name, 5));

        $contender = $this->contenderDialect()->locks($this->secondConnection());
        $start = microtime(true);
        $this->assertFalse($contender->acquire($name, 1), 'a second session must not get a lock the first holds');
        $elapsed = microtime(true) - $start;
        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'acquire() must actually wait close to the timeout, not fail immediately');

        $this->assertTrue($holder->release($name));
    }

    public function testReleaseLetsTheContenderIn(): void
    {
        $name = $this->lockName('release-lets-in');
        $dialect = EntityManagerFactory::getDialect();

        $holder = $dialect->locks(EntityManagerFactory::getInstance()->getConnection());
        $this->assertTrue($holder->acquire($name, 5));

        $contender = $this->contenderDialect()->locks($this->secondConnection());
        $this->assertFalse($contender->acquire($name, 0));

        $this->assertTrue($holder->release($name));
        $this->assertTrue($contender->acquire($name, 5), 'the contender must get the lock once the holder releases it');
        $this->assertTrue($contender->release($name));
    }

    public function testSynchronizedReleasesOnException(): void
    {
        $name = $this->lockName('sync-exception');
        $dialect = EntityManagerFactory::getDialect();
        $lock = $dialect->locks(EntityManagerFactory::getInstance()->getConnection());

        try {
            $lock->synchronized($name, 5, static function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('Expected the critical section exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        // Prove the lock was actually released server-side: a different session can now take it.
        $contender = $this->contenderDialect()->locks($this->secondConnection());
        $this->assertTrue($contender->acquire($name, 1));
        $this->assertTrue($contender->release($name));
    }

    public function testNestedSynchronizedOnTheSameNameDoesNotDeadlock(): void
    {
        $name = $this->lockName('nested');
        $dialect = EntityManagerFactory::getDialect();
        $lock = $dialect->locks(EntityManagerFactory::getInstance()->getConnection());

        $result = $lock->synchronized($name, 5, function () use ($lock, $name) {
            return $lock->synchronized($name, 5, static fn () => 'inner');
        });
        $this->assertSame('inner', $result);

        $contender = $this->contenderDialect()->locks($this->secondConnection());
        $this->assertTrue($contender->acquire($name, 1), 'the outer synchronized() must have released once both levels unwound');
        $this->assertTrue($contender->release($name));
    }

    /**
     * The exact self-timeout this build step fixes (§4.12, pg-run4: "Could not acquire advisory lock
     * lock:locations:location-slug:… within 10s"): `AdvisoryLock::synchronized()` and nested service code each
     * call `getDialect()->locks($connection)` independently — TWO SEPARATE `locks()` calls, not one reused
     * variable (unlike {@see testNestedSynchronizedOnTheSameNameDoesNotDeadlock} above). Before the fix, each
     * call on PostgreSQL got a FRESH `PostgresLockService` with its own empty depth map, which opened its own
     * direct connection and tried `pg_try_advisory_lock` on a BRAND NEW backend session for a name a DIFFERENT
     * session (the first call's) already held — a real session-scoped PostgreSQL advisory lock is not visible
     * as "already held" across two different backend connections, so the inner acquire genuinely blocked until
     * timeout. Caching one `PostgresLockService` per scope on the dialect singleton makes both calls return the
     * SAME instance, so the existing re-entrant depth map in {@see
     * \DDD\Domain\Base\Repo\DB\Dialect\Locks\AbstractLockService} actually applies. On MariaDB this was never
     * broken — `GET_LOCK` is itself re-entrant and ref-counted per session (MariaDB ≥ 10.0.2) — so this test is
     * equally valid, and green, on both engines.
     */
    public function testNestedAcquireThroughTwoSeparateLocksCallsOnTheSameProcessDoesNotSelfDeadlock(): void
    {
        $name = $this->lockName('nested-separate-calls');
        $connection = EntityManagerFactory::getInstance()->getConnection();

        // Two INDEPENDENT locks() calls — simulates AdvisoryLock::synchronized() calling into nested service
        // code that independently resolves the dialect's lock service again for the same scope.
        $outer = EntityManagerFactory::getDialect()->locks($connection);
        $inner = EntityManagerFactory::getDialect()->locks($connection);

        $start = microtime(true);
        $result = $outer->synchronized($name, 5, function () use ($inner, $name) {
            return $inner->synchronized($name, 5, static fn () => 'inner');
        });
        $elapsed = microtime(true) - $start;

        $this->assertSame('inner', $result);
        $this->assertLessThan(2.0, $elapsed, 'the nested acquire must succeed immediately, never wait out any part of the timeout');

        // Fully released — a genuinely separate process can now take the name.
        $contender = $this->contenderDialect()->locks($this->secondConnection());
        $this->assertTrue($contender->acquire($name, 1));
        $this->assertTrue($contender->release($name));
    }

    public function testSynchronizedThrowsLockTimeoutExceptionAgainstARealBusyLock(): void
    {
        $name = $this->lockName('timeout-exception');
        $dialect = EntityManagerFactory::getDialect();

        $holder = $dialect->locks(EntityManagerFactory::getInstance()->getConnection());
        $this->assertTrue($holder->acquire($name, 5));

        $contender = $this->contenderDialect()->locks($this->secondConnection());
        $this->expectException(LockTimeoutException::class);
        try {
            $contender->synchronized($name, 1, static fn () => 'never reached');
        } finally {
            $holder->release($name);
        }
    }

    /**
     * {@see DatabaseSchemaDiffService::applyDiffs()}'s cross-request mutex now runs through LockService — this
     * proves the migrated call site's exact lock name round-trips through the real dialect on both engines,
     * without needing a full entity/diff fixture to drive applyDiffs() itself.
     */
    public function testTheSchemaDiffApplyLockNameWorksThroughTheDialect(): void
    {
        $dialect = EntityManagerFactory::getDialect();
        $holder = $dialect->locks(EntityManagerFactory::getInstance()->getConnection());

        $this->assertTrue($holder->acquire(DatabaseSchemaDiffService::APPLY_LOCK_NAME, 5));

        $contender = $this->contenderDialect()->locks($this->secondConnection());
        $this->assertFalse(
            $contender->acquire(DatabaseSchemaDiffService::APPLY_LOCK_NAME, 0),
            'a second apply must be refused the lock while the first holds it, exactly like the BadRequestException path in applyDiffs()'
        );

        $this->assertTrue($holder->release(DatabaseSchemaDiffService::APPLY_LOCK_NAME));
        $this->assertTrue($contender->acquire(DatabaseSchemaDiffService::APPLY_LOCK_NAME, 1));
        $this->assertTrue($contender->release(DatabaseSchemaDiffService::APPLY_LOCK_NAME));
    }
}
