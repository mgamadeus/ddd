<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

class QueryOptionsPropertyMapping
{
    /**
     * $value carries the FILTER VALUE through a repo's mapping function and back:
     * {@see FiltersOptions::getFiltersExpressionForDoctrineQueryBuilder()} replaces the value with
     * $mapping->value unconditionally, so whatever the parser produced must survive the round trip. That is every
     * type {@see FiltersOptions::$value} can hold — a list operator (in / ni / bw) carries an ARRAY of scalars, and
     * a numeric literal arrives as a float (the parser casts every number). A string-only type fatals the mapping
     * closure on those filters instead of filtering, e.g. `?filters=status in ['deleted','canceled']`.
     *
     * @param string $propertyName
     * @param string|int|float|array|null $value
     */
    public function __construct(public string $propertyName, public string|int|float|array|null $value = null)
    {
    }
}