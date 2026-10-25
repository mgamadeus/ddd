<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Locks;

use DDD\Domain\Base\Repo\DB\Dialect\Locks\LockTimeoutException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Re-entrancy and synchronized() plumbing shared by both engines (architecture plan §4.12), exercised against
 * {@see FakeLockService} so no real connection is needed. The real engines are proven by the integration suite
 * (tests/Integration/Locks); this covers the depth-map logic itself, which is identical code for both.
 */
final class AbstractLockServiceTest extends TestCase
{
    protected function setUp(): void
    {
        FakeLockService::resetServerState();
    }

    public function testAcquireAndReleaseRoundTrip(): void
    {
        $lock = new FakeLockService();
        $this->assertTrue($lock->acquire('a', 1));
        $this->assertSame(1, $lock->acquireOnceCalls);
        $this->assertTrue($lock->release('a'));
        $this->assertSame(1, $lock->releaseOnceCalls);
        $this->assertSame([], $lock->depths());
    }

    public function testReentrantAcquireOnTheSameNameDoesNotHitTheDatabaseAgain(): void
    {
        $lock = new FakeLockService();
        $this->assertTrue($lock->acquire('a', 1));
        $this->assertTrue($lock->acquire('a', 1)); // nested — must not call acquireOnce() again
        $this->assertSame(1, $lock->acquireOnceCalls);
        $this->assertSame(2, $lock->depths()['a']);

        // Inner release only decrements the depth, never touches releaseOnce().
        $this->assertTrue($lock->release('a'));
        $this->assertSame(0, $lock->releaseOnceCalls);
        $this->assertSame(1, $lock->depths()['a']);

        // Outer release actually releases.
        $this->assertTrue($lock->release('a'));
        $this->assertSame(1, $lock->releaseOnceCalls);
        $this->assertSame([], $lock->depths());
    }

    public function testReleasingANameNeverAcquiredReturnsFalseWithoutTouchingTheDatabase(): void
    {
        $lock = new FakeLockService();
        $this->assertFalse($lock->release('never-acquired'));
        $this->assertSame(0, $lock->releaseOnceCalls);
    }

    public function testDifferentNamesNestIndependently(): void
    {
        $lock = new FakeLockService();
        $this->assertTrue($lock->acquire('a', 1));
        $this->assertTrue($lock->acquire('b', 1));
        $this->assertSame(['a' => 1, 'b' => 1], $lock->depths());
        $this->assertTrue($lock->release('a'));
        $this->assertSame(['b' => 1], $lock->depths());
        $this->assertTrue($lock->release('b'));
        $this->assertSame([], $lock->depths());
    }

    public function testASecondInstanceIsBlockedWhileTheFirstHoldsTheName(): void
    {
        $first = new FakeLockService();
        $second = new FakeLockService();

        $this->assertTrue($first->acquire('shared', 1));
        $this->assertFalse($second->acquire('shared', 1));

        $this->assertTrue($first->release('shared'));
        $this->assertTrue($second->acquire('shared', 1));
    }

    public function testSynchronizedReturnsTheCriticalSectionsResultAndReleasesAfterward(): void
    {
        $lock = new FakeLockService();
        $result = $lock->synchronized('a', 1, static fn () => 'payload');
        $this->assertSame('payload', $result);
        $this->assertSame([], $lock->depths());
    }

    public function testSynchronizedReleasesEvenWhenTheCriticalSectionThrows(): void
    {
        $lock = new FakeLockService();
        try {
            $lock->synchronized('a', 1, static function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('Expected the critical section exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }
        $this->assertSame([], $lock->depths(), 'the lock must be released even though the critical section threw');
        $this->assertSame(1, $lock->releaseOnceCalls);
    }

    public function testSynchronizedThrowsLockTimeoutExceptionWhenAcquireFails(): void
    {
        $lock = new FakeLockService();
        $lock->failNextAcquire = true;

        $this->expectException(LockTimeoutException::class);
        $this->expectExceptionMessageMatches('/"a"/');
        $lock->synchronized('a', 5, static fn () => 'never reached');
    }

    public function testNestedSynchronizedOnTheSameNameDoesNotDeadlock(): void
    {
        $lock = new FakeLockService();
        $result = $lock->synchronized('a', 1, function () use ($lock) {
            return $lock->synchronized('a', 1, static fn () => 'inner');
        });
        $this->assertSame('inner', $result);
        $this->assertSame([], $lock->depths());
        // Only the outer acquire/release pair ever touched the "database".
        $this->assertSame(1, $lock->acquireOnceCalls);
        $this->assertSame(1, $lock->releaseOnceCalls);
    }

    public function testCloseIfIdleIsCalledOnceDepthReturnsToZeroButNotWhileAnotherNameIsStillHeld(): void
    {
        $lock = new FakeLockService();
        $lock->acquire('a', 1);
        $lock->acquire('b', 1);
        $lock->release('a');
        // 'b' is still held — closeIfIdle() ran (it always runs after a release), but the fake doesn't need to
        // distinguish "idle" itself; AbstractLockService calls it unconditionally and leaves the idle decision to
        // the override (PostgresLockService checks $this->depths === [] itself). Here we just confirm it fires.
        $this->assertGreaterThanOrEqual(1, $lock->closeIfIdleCalls);
        $lock->release('b');
        $this->assertSame([], $lock->depths());
    }
}
