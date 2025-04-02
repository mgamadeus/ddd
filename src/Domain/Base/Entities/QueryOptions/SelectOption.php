<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;

class SelectOption extends ValueObject
{
    /** @var string The property name to select */
    public ?string $propertyName;

    /** @var FiltersDefinition|null The definition the option is based on */
    protected ?FiltersDefinition $filtersDefinition = null;

    /**
     * Returns the filters definition.
     *
     * @return FiltersDefinition|null
     */
    public function getFiltersDefinition(): ?FiltersDefinition
    {
        return $this->filtersDefinition;
    }

    /**
     * Sets the filters definition.
     *
     * @param FiltersDefinition $filtersDefinition
     */
    public function setFiltersDefinition(FiltersDefinition $filtersDefinition): void
    {
        $this->filtersDefinition = $filtersDefinition;
    }

    /**
     * @param string|null $propertyName
     * @throws BadRequestException
     */
    public function __construct(string $propertyName = null)
    {
        $this->propertyName = $propertyName;
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return $this->propertyName;
    }
}