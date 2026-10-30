<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The column an orderBy sorts and whether it is the virtual search column of a
 * #[Translatable(fullTextIndex: true)] property instead of the property's own column.
 */
class OrderByColumn extends ValueObject
{
    public function __construct(
        public readonly string $columnName,
        public readonly bool $routedToSearchColumn,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
