<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Cache;

use DDD\Infrastructure\Cache\Cache;
use PHPUnit\Framework\TestCase;

/**
 * The atomic counter and lock contract of {@see Cache}, run against every backend that supports it (one subclass per
 * backend): a lock is taken once and released only by its owner; a counter's TTL counts from its creation and is
 * never extended by a later increment.
 */
abstract class CacheCounterAndLockContractTestCase extends TestCase
{
    /** The backend under test; the test is skipped when it is not available */
    abstract protected function createCache(): Cache;

    /**
     * How late after its TTL the backend may still report an entry: Redis expires to the millisecond, APCu compares
     * whole seconds. Below 1.1 s, so a TTL re-armed at 1.5 s (alive until 3.5 s) is still caught at 2 + 0.4 + 1.1.
     */
    protected function getExpiryToleranceSeconds(): float
    {
        return 0.0;
    }

    protected Cache $cache;

    protected string $keyPrefix;

    protected function setUp(): void
    {
        $this->cache = $this->createCache();
        $this->keyPrefix = 'contract.' . bin2hex(random_bytes(4)) . '.';
    }

    public function testASecondAcquireOfAHeldLockGetsNull(): void
    {
        $token = $this->cache->acquireLock($this->keyPrefix . 'lock', 30);

        self::assertIsString($token);
        self::assertNull($this->cache->acquireLock($this->keyPrefix . 'lock', 30));
        self::assertTrue($this->cache->releaseLock($this->keyPrefix . 'lock', $token));
    }

    public function testOnlyTheOwnerReleasesTheLock(): void
    {
        $token = (string)$this->cache->acquireLock($this->keyPrefix . 'lock', 30);

        self::assertFalse($this->cache->releaseLock($this->keyPrefix . 'lock', 'not-the-owner'));
        self::assertNull($this->cache->acquireLock($this->keyPrefix . 'lock', 30), 'a wrong token leaves the lock held');
        self::assertTrue($this->cache->releaseLock($this->keyPrefix . 'lock', $token));
        self::assertFalse($this->cache->releaseLock($this->keyPrefix . 'lock', $token), 'released once');
        self::assertIsString($this->cache->acquireLock($this->keyPrefix . 'lock', 30), 'free again after the release');
    }

    public function testAnExpiredLockCanBeTakenAgainAndTheOldOwnerCannotReleaseIt(): void
    {
        $firstToken = (string)$this->cache->acquireLock($this->keyPrefix . 'lock', 1);
        usleep((int)((2.2 + $this->getExpiryToleranceSeconds()) * 1_000_000));

        $secondToken = $this->cache->acquireLock($this->keyPrefix . 'lock', 30);
        self::assertIsString($secondToken);
        self::assertFalse($this->cache->releaseLock($this->keyPrefix . 'lock', $firstToken), 'the expired owner never removes the new lock');
        self::assertTrue($this->cache->releaseLock($this->keyPrefix . 'lock', $secondToken));
    }

    public function testTheOwnerRefreshKeepsTheLockPastItsFirstTtl(): void
    {
        $token = (string)$this->cache->acquireLock($this->keyPrefix . 'lease', 2);
        // a heartbeat every second re-arms the 2 s lock: 3.5 s after the acquire it is still held
        for ($beat = 0; $beat < 3; $beat++) {
            usleep(1_000_000);
            self::assertTrue($this->cache->refreshLock($this->keyPrefix . 'lease', $token, 2));
        }
        usleep(500_000);

        self::assertNull($this->cache->acquireLock($this->keyPrefix . 'lease', 30), 'still held after 3.5 s');
        self::assertTrue($this->cache->releaseLock($this->keyPrefix . 'lease', $token));
    }

    public function testARefreshWithAWrongTokenFailsAndDoesNotExtend(): void
    {
        $token = (string)$this->cache->acquireLock($this->keyPrefix . 'lease', 2);

        self::assertFalse($this->cache->refreshLock($this->keyPrefix . 'lease', 'not-the-owner', 60));
        usleep((int)((2.4 + $this->getExpiryToleranceSeconds()) * 1_000_000));
        self::assertIsString($this->cache->acquireLock($this->keyPrefix . 'lease', 30), 'expired on its own 2 s TTL');
        self::assertFalse($this->cache->releaseLock($this->keyPrefix . 'lease', $token));
    }

    public function testARefreshAfterExpiryFails(): void
    {
        $token = (string)$this->cache->acquireLock($this->keyPrefix . 'lease', 1);
        usleep((int)((2.2 + $this->getExpiryToleranceSeconds()) * 1_000_000));

        self::assertFalse($this->cache->refreshLock($this->keyPrefix . 'lease', $token, 30));
        self::assertIsString($this->cache->acquireLock($this->keyPrefix . 'lease', 30), 'the refresh did not revive it');
    }

    public function testARefreshNeedsATtl(): void
    {
        $token = (string)$this->cache->acquireLock($this->keyPrefix . 'lease', 30);

        $this->expectException(\InvalidArgumentException::class);
        $this->cache->refreshLock($this->keyPrefix . 'lease', $token, 0);
    }

    public function testALockNeedsATtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cache->acquireLock($this->keyPrefix . 'lock', 0);
    }

    public function testLocksAndCountersAreSeparateKeyspaces(): void
    {
        $this->cache->increment($this->keyPrefix . 'shared', 1, 30);

        self::assertIsString($this->cache->acquireLock($this->keyPrefix . 'shared', 30));
    }

    public function testIncrementsDoNotExtendTheCounterTtl(): void
    {
        // the counter is created with a 2 s TTL; increments every 0.5 s must not keep it alive: 2.4 s after the
        // creation it is gone (a re-armed TTL would keep it until 3.5 s)
        $createdAt = microtime(true);
        self::assertSame(1, $this->cache->increment($this->keyPrefix . 'lease', 1, 2));
        for ($round = 0; $round < 3; $round++) {
            usleep(500_000);
            $this->cache->increment($this->keyPrefix . 'lease', 1, 2);
        }
        usleep((int)max(0, ($createdAt + 2.4 + $this->getExpiryToleranceSeconds() - microtime(true)) * 1_000_000));

        self::assertSame(0, $this->cache->getCounter($this->keyPrefix . 'lease'), 'expired 2 s after its creation');
        self::assertSame(1, $this->cache->increment($this->keyPrefix . 'lease', 1, 2), 'created anew');
    }

    public function testACounterWithoutTtlCountsAndIsDeleted(): void
    {
        self::assertSame(5, $this->cache->increment($this->keyPrefix . 'plain', 5));
        self::assertSame(3, $this->cache->decrement($this->keyPrefix . 'plain', 2));
        self::assertSame(3, $this->cache->getCounter($this->keyPrefix . 'plain'));
        $this->cache->deleteCounter($this->keyPrefix . 'plain');
        self::assertSame(0, $this->cache->getCounter($this->keyPrefix . 'plain'));
    }

    /** Polls $condition for up to $seconds (expiry is not to the millisecond on every backend) */
    protected function waitFor(callable $condition, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline && !$condition()) {
            usleep(100_000);
        }
    }
}
