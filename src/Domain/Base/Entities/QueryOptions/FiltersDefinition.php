<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\MethodNotAllowedException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use ReflectionException;

/**
 * @method FiltersDefinitions getParent()
 * @property FiltersDefinitions $parent
 */
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

    /**
     * Returns Reference Class DB Repo (and returns DBEntity in case of reference class being EntitySet)
     * @return string|null
     * @throws MethodNotAllowedException
     * @throws ReflectionException
     */
    public function getReferenceClassRepo(): ?string
    {
        $referenceClass = $this->getReferenceClass();
        if (!$referenceClass) {
            return null;
        }
        $reflectionClass = ReflectionClass::instance($referenceClass);
        if (!$reflectionClass->hasTrait(QueryOptionsTrait::class)) {
            throw new MethodNotAllowedException("Cannot use class {$referenceClass} as reference class of FiltersOptions as it has no QueryOptions trait.");
        }
        /** @var DefaultObject $referenceClass */
        $repoClass = $referenceClass::getRepoClass(LazyLoadRepo::DB);
        return $repoClass;
    }

    /**
     * Returns reference class of the FitlersDefinition,
     * In case of perent FilterDefinitions has an EntitySet as reference class, returns Entity class instead
     * @return string|null
     */
    public function getReferenceClass(): ?string
    {
        $filtersDefinitions = $this->getParent();
        if (!$filtersDefinitions) {
            return null;
        }
        if (isset($filtersDefinitions->referenceClassName)) {
            $referenceClass = $filtersDefinitions->referenceClassName;
            if (is_a($referenceClass, EntitySet::class, true)) {
                /** @var EntitySet $referenceClass */
                $referenceClass = $referenceClass::getEntityClass();
            }
            return $referenceClass;
        }
        return null;
    }
}