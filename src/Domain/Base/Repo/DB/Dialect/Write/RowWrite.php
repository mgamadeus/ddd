<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One row of a write: its {@see ColumnWrites} and the explicit id the caller set (null: the database generates it).
 */
class RowWrite extends ValueObject
{
    public function __construct(
        public readonly ColumnWrites $columnWrites,
        public readonly int|string|null $idValue = null,
    ) {
        parent::__construct();
    }

    /** Every instance is its own element: equal values may legitimately repeat, and order matters. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
