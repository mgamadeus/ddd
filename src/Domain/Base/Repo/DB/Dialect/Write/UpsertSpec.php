<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Dialect-neutral description of one row's write (dual-dialect architecture plan §4.2/§4.6). `$idValue` is the
 * row's explicit id when the caller set one (identifier columns are never listed in `$columns` — the id gets its
 * own slot so a builder can decide INSERT vs. UPDATE_BY_ID without scanning `$columns` for an identifier flag).
 */
class UpsertSpec extends ValueObject
{
    /**
     * @param ColumnWrites $columns Every non-identifier column this row writes, in declaration order.
     * @param string[] $conflictTargetColumns Unquoted column names forming the `ON CONFLICT (...)` arbiter;
     *   `['id']` for an id-present row, the resolved unique key for a key-based upsert, `[]` when none was
     *   resolved (plain INSERT — {@see ConflictTargetResolver}).
     */
    public function __construct(
        public readonly string $table,
        public readonly ColumnWrites $columns,
        public readonly int|string|null $idValue = null,
        public readonly array $conflictTargetColumns = [],
        public readonly bool $useInsertIgnore = false,
    ) {
        parent::__construct();
    }

    public function hasId(): bool
    {
        return $this->idValue !== null;
    }

    /** Every instance is its own element: identical values may legitimately repeat, and insertion order matters. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
