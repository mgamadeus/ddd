<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Locks;

use RuntimeException;

/**
 * `PostgresLockService`'s direct lock connection silently reconnected while a lock was believed held (architecture
 * plan §4.12: "`pg_backend_pid()` asserted equal at lock and unlock"). A PostgreSQL session advisory lock is tied
 * to the backend process that took it — if the connection reconnected (pooling, a killed backend, a network
 * blip), the lock was released by the server the moment the old backend went away, without `LockService` ever
 * being told. Thrown instead of silently calling `pg_advisory_unlock` on a new session that never held the lock
 * (which would either no-op or, worse, release some other holder's lock on the same key).
 */
class LockConnectionLostException extends RuntimeException
{
}
