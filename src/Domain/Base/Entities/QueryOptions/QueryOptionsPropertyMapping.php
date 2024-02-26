<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

class QueryOptionsPropertyMapping
{
    public function __construct(public string $propertyName, public ?string $value = null)
    {
    }
}