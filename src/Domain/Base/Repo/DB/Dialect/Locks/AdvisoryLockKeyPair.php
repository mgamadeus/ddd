<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Locks;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The two-int key of PostgreSQL's `pg_advisory_lock(key1 int, key2 int)` form, as {@see LockKey::internalKey()}
 * derives it: `namespace` is key1 (the high 32 bits of the shared 64-bit keyspace, always non-zero), `id` is key2
 * (the low 32 bits).
 */
class AdvisoryLockKeyPair extends ValueObject
{
    public function __construct(
        public readonly int $namespace,
        public readonly int $id,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
