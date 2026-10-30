<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Locks;

/**
 * Named application locks, one contract on both engines (architecture plan
 * `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §4.3/§4.12, D3). `MariaDbLockService` runs
 * `GET_LOCK`/`RELEASE_LOCK` on the caller's own connection, unchanged from today. `PostgresLockService` runs
 * `pg_advisory_lock`/`pg_advisory_unlock` on a dedicated, non-pooled connection (a session-scoped advisory lock on
 * a PgBouncer transaction-mode connection leaks and loses mutual exclusion), bounded by its own poll loop instead
 * of PostgreSQL's blocking form, so both engines return false on timeout rather than blocking forever.
 *
 * Re-entrant per name, within one `LockService` instance: a nested `acquire()`/`synchronized()` call for a name
 * already held by this instance succeeds immediately without touching the database again, and only the outermost
 * `release()` actually runs `RELEASE_LOCK`/`pg_advisory_unlock`. Nesting different names is independent — each
 * name has its own depth.
 */
interface LockService
{
    /**
     * @return bool false on timeout (the `GET_LOCK(name, timeout)` contract on both engines); any other failure
     *     throws instead of returning false.
     */
    public function acquire(string $name, int $timeoutSeconds): bool;

    /**
     * @return bool whether a RELEASE_LOCK/pg_advisory_unlock actually ran and reported the lock was held; false
     *     when this instance held no depth for $name to release (never acquired, or already fully released).
     */
    public function release(string $name): bool;

    /**
     * Acquires $name, runs $criticalSection, releases $name — even when $criticalSection throws.
     *
     * @throws LockTimeoutException if $name could not be acquired within $timeoutSeconds.
     */
    public function synchronized(string $name, int $timeoutSeconds, callable $criticalSection): mixed;
}
