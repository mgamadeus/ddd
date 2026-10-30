<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The column list and the value expressions of one row's INSERT, with the values and DBAL types bound to them.
 */
class InsertColumnsAndValues extends ValueObject
{
    public function __construct(
        /** @var string[] Quoted column names */
        public readonly array $quotedColumns,
        /** @var string[] One value expression per column */
        public readonly array $valueExpressions,
        /** @var array The bound values, in placeholder order */
        public readonly array $values,
        /** @var string[] The DBAL type of each bound value */
        public readonly array $types,
    ) {
        parent::__construct();
    }

    /** Every instance is its own element: equal values may legitimately repeat, and order matters. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
