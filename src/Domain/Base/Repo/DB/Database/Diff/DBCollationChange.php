<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Describes a table-level collation transition between the current (live) state and the target
 * (entity-derived) state. Attached to {@see DBTableDiff::$collationChange}; null when collation
 * matches.
 */
class DBCollationChange extends ValueObject
{
    /** @var string Live collation (from INFORMATION_SCHEMA.TABLES.TABLE_COLLATION). */
    public string $from;

    /** @var string Target collation (from the DatabaseModel). */
    public string $to;
}
