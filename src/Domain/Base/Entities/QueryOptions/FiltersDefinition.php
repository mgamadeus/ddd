<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ValueObject;

class FiltersDefinition extends ValueObject
{
    /** @var string The property name the filter will be applied to */
    public ?string $propertyName;

    /** @var string|int Options for the property values to be filtered for (optional) */
    public ?array $options;

    /**
     * @var ExpandDefinition|null if this filter is based on an expand property, this is the corresponding ExpandDefinition attached
     */
    protected ?ExpandDefinition $expandDefinition = null;

    public function __construct(string $propertyName = null, array $options = null, ?ExpandDefinition $expandDefinition = null)
    {
        $this->propertyName = $propertyName;
        $this->options = $options;
        $this->expandDefinition = $expandDefinition;
        parent::__construct();
    }

    /**
     * @return ExpandDefinition|null
     */
    public function getExpandDefinition(): ?ExpandDefinition
    {
        return $this->expandDefinition;
    }

    /**
     * @param ExpandDefinition|null $expandDefinition
     */
    public function setExpandDefinition(?ExpandDefinition $expandDefinition): void
    {
        $this->expandDefinition = $expandDefinition;
    }

    public function uniqueKey(): string
    {
        return $this->propertyName;
    }


}