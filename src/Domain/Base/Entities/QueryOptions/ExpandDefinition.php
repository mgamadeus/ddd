<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Results paginationFilterAndSorting
 */
class ExpandDefinition extends ValueObject
{
    /** @var string The proeprty name to expand */
    public ?string $propertyName;

    /** @var FiltersDefinitions Allowed filtering property definitions */
    public FiltersDefinitions $filtersDefinitions;

    /** @var string[] Allowed orderBy property definitions */
    public array $orderByDefinitions;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->propertyName);
    }
}