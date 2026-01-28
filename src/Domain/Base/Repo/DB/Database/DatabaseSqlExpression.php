<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

/**
 * Represents a raw SQL expression that should be emitted as-is (unquoted).
 *
 * Used for column defaults like MariaDB VECTOR defaults:
 *   DEFAULT VEC_FromText('[0,0,0]')
 */
class DatabaseSqlExpression
{
    public function __construct(private readonly string $sql)
    {
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
