<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What decides whether two rows of a batch can share one statement: their initialized field names and which of them
 * replace instead of merge ({@see BatchPlanner}).
 */
class BatchRowShape extends ValueObject
{
    public function __construct(
        /** @var string[] */
        public readonly array $fieldNames,
        /** @var string[] */
        public readonly array $columnsToReplace,
    ) {
        parent::__construct();
    }

    /** Every instance is its own element: equal values may legitimately repeat, and order matters. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }

    /** @return string The shape as one comparable string */
    public function getShapeKey(): string
    {
        return implode(',', $this->fieldNames) . '|' . implode(',', $this->columnsToReplace);
    }
}
