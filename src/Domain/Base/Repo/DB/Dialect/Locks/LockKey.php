<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Locks;

use InvalidArgumentException;

/**
 * Key derivation for PostgreSQL advisory locks (architecture plan §4.12 "key space"). PostgreSQL's single-bigint
 * `pg_advisory_lock(key bigint)` and two-int `pg_advisory_lock(key1 int, key2 int)` forms share ONE 64-bit
 * keyspace — the server packs key1 into the high 32 bits and key2 into the low 32 bits of the same bigint the
 * single-arg form addresses directly (PostgreSQL docs, "Advisory Locks"). That is what makes the two forms here
 * collision-free with each other:
 *
 * - {@see applicationKey()} — every name `LockService::acquire()` takes — folds the name through `crc32()` into
 *   the low 32 bits only; the high 32 bits are always zero.
 * - {@see internalKey()} — reserved for DDD-internal locks that are not yet built on top of `LockService` and so
 *   are out of this build step's scope (identity catch-up, the Track trigger, schema-diff apply; architecture plan
 *   §4.12 "Coverage") — always carries a non-zero namespace constant in the high 32 bits.
 *
 * Zero vs. non-zero high bits is the entire separation; neither form is compared against MariaDB, which keeps its
 * own string lock names (`GET_LOCK`) and never needs a numeric key at all.
 */
final class LockKey
{
    protected function __construct()
    {
        // Static-only: no instance holds state, so there is nothing a constructor could usefully set up.
    }

    /**
     * `crc32()` is unsigned 32-bit (0..4294967295), which always fits in PHP's 64-bit `int` with the high 32 bits
     * left at zero — exactly the property {@see internalKey()} relies on never colliding with.
     */
    public static function applicationKey(string $name): int
    {
        return crc32($name);
    }

    /**
     * @return array{0: int, 1: int} [namespace, id] for PostgreSQL's two-int `pg_advisory_lock(key1, key2)` form.
     * @throws InvalidArgumentException if $namespace is 0 — that would collide with {@see applicationKey()}'s
     *     always-zero high bits.
     */
    public static function internalKey(int $namespace, int $id): array
    {
        if ($namespace === 0) {
            throw new InvalidArgumentException(
                'LockKey::internalKey() namespace must be non-zero: 0 collides with applicationKey()\'s always-zero high bits.'
            );
        }
        return [$namespace, $id];
    }
}
