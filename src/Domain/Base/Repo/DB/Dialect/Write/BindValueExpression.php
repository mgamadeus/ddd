<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The SQL a column's value takes in an INSERT / UPDATE (`?`, `ST_GeomFromText(?, 0)`, `?::vector`, or a literal that
 * binds nothing) and the value bound to it.
 */
class BindValueExpression extends ValueObject
{
    public function __construct(
        public readonly string $sql,
        public readonly mixed $value,
        public readonly bool $bindsValue,
    ) {
        parent::__construct();
    }

    /** Every instance is its own element: equal values may legitimately repeat, and order matters. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
