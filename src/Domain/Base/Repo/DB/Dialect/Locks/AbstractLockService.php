<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Locks;

/**
 * Shared re-entrancy and `synchronized()` plumbing for both engines' {@see LockService} (architecture plan §4.12:
 * "re-entrancy via a per-process name → depth map"). Neither `GET_LOCK` nor `pg_advisory_lock` count re-entrant
 * acquisitions on their own — a second `GET_LOCK` for a name this same session already holds just returns success
 * without incrementing anything server-side, so a naive nested `synchronized()` would have its inner `release()`
 * drop the outer caller's lock. The depth map here is what makes nesting (same name or different names) safe: only
 * the transition into depth 0 touches the database, via {@see acquireOnce()}/{@see releaseOnce()}, which the two
 * concrete classes implement as a single, non-reentrant attempt.
 */
abstract class AbstractLockService implements LockService
{
    /** @var array<string, int> lock name => re-entrant depth currently held by this instance */
    protected array $depths = [];

    final public function acquire(string $name, int $timeoutSeconds): bool
    {
        if (($this->depths[$name] ?? 0) > 0) {
            $this->depths[$name]++;
            return true;
        }

        $acquired = $this->acquireOnce($name, $timeoutSeconds);
        if ($acquired) {
            $this->depths[$name] = 1;
        } else {
            // Nothing was ever acquired, but a connection may have just been opened to try (PostgresLockService) —
            // let the subclass close it again if no OTHER name is held either, instead of leaving it idle.
            $this->closeIfIdle();
        }
        return $acquired;
    }

    final public function release(string $name): bool
    {
        $depth = $this->depths[$name] ?? 0;
        if ($depth === 0) {
            return false;
        }
        if ($depth > 1) {
            $this->depths[$name] = $depth - 1;
            return true;
        }

        $released = $this->releaseOnce($name);
        unset($this->depths[$name]);
        $this->closeIfIdle();
        return $released;
    }

    final public function synchronized(string $name, int $timeoutSeconds, callable $criticalSection): mixed
    {
        if (!$this->acquire($name, $timeoutSeconds)) {
            throw new LockTimeoutException($name, $timeoutSeconds);
        }
        try {
            return $criticalSection();
        } finally {
            $this->release($name);
        }
    }

    /** A single, non-reentrant acquisition attempt bounded by $timeoutSeconds; false on timeout. */
    abstract protected function acquireOnce(string $name, int $timeoutSeconds): bool;

    /** A single, non-reentrant release of a lock this instance's depth map confirms it holds. */
    abstract protected function releaseOnce(string $name): bool;

    /**
     * Called whenever this instance's depth map may have just become empty (a failed {@see acquireOnce()} that
     * never incremented anything, or a {@see releaseOnce()} that brought a name's depth to 0). No-op by default
     * (MariaDbLockService runs on the caller's own connection and owns nothing to close); PostgresLockService
     * overrides it to close its dedicated direct connection once no name is held, per §4.12's "opened on the first
     * acquisition of a name, closed in finally" — a long-lived process should not hold a direct, non-pooled
     * connection open for longer than it actually holds a lock.
     */
    protected function closeIfIdle(): void
    {
    }
}
