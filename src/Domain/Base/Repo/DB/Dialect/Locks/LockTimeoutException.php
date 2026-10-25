<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Locks;

use RuntimeException;

/**
 * `LockService::synchronized()` could not acquire its lock within the given timeout — the `GET_LOCK(name,
 * timeout)` contract on both engines (architecture plan §4.12). `acquire()` itself returns false instead of
 * throwing; this exception is `synchronized()`'s own translation of that false into a throw, since a critical
 * section has no caller-visible "didn't run" return value to carry the false through.
 */
class LockTimeoutException extends RuntimeException
{
    public function __construct(string $lockName, int $timeoutSeconds)
    {
        parent::__construct("Could not acquire lock \"$lockName\" within {$timeoutSeconds}s.");
    }
}
