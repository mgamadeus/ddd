<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Schema;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A rendered column type plus its collation clause, as {@see PostgresDdlRenderer} derives it from a reference
 * column — e.g. `varchar(255)` and ` COLLATE "…"`, or `bigint` and an empty clause.
 */
class ColumnTypeDefinition extends ValueObject
{
    /**
     * @param string $type The SQL type, e.g. `bigint`, `varchar(255)`
     * @param string $collationClause The collation clause including its leading space, or '' when uncollated
     */
    public function __construct(
        public readonly string $type,
        public readonly string $collationClause,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
