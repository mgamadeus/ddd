<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionUnionType;
use DDD\Infrastructure\Traits\AfterConstruct\Attributes\AfterConstruct;
use DDD\Infrastructure\Traits\ReflectorTrait;
use ReflectionException;
use ReflectionNamedType;

trait QueryOptionsTrait
{
    use ReflectorTrait;

    /** @var AppliedQueryOptions Applied Query Options */
    protected AppliedQueryOptions $queryOptions;

    protected static $defaultQueryOptions = [];

    #[AfterConstruct]
    public function initQueryOptions()
    {
        $this->getQueryOptions();
    }

    /**
     * Returns current query options
     * @return AppliedQueryOptions|null
     */
    public function getQueryOptions(): ?AppliedQueryOptions
    {
        if (!isset($this->queryOptions)) {
            $this->queryOptions = clone static::getDefaultQueryOptions();
        }
        return $this->queryOptions ?? null;
    }

    /**
     * Sets query options
     * @param AppliedQueryOptions $queryOptions
     * @return void
     */
    public function setQueryOptions(AppliedQueryOptions &$queryOptions)
    {
        $this->queryOptions = $queryOptions;
    }

    /**
     * Returns the default QueryOptions instance for class
     * @param int $depth used for avoiding getting filters of expand options at unlimited depth
     * @return QueryOptions
     * @throws ReflectionException
     */
    public static function getDefaultQueryOptions(int $depth = 1): AppliedQueryOptions
    {
        $className = static::class;
        if (property_exists(static::class, 'isArgusEntity')) {
            $reflectionClass = ReflectionClass::instance(static::class);
            $className = $reflectionClass->getParentClass()->getName();
        }

        if (isset(self::$defaultQueryOptions[$className])) {
            return self::$defaultQueryOptions[$className];
        }
        /** @var QueryOptions $queryOptions */
        $queryOptionsAttributeInstance = static::getAttributeInstance(QueryOptions::class);
        if (!$queryOptionsAttributeInstance) {
            $queryOptionsAttributeInstance = new QueryOptions();
        }
        $queryOptions = new AppliedQueryOptions($queryOptionsAttributeInstance);
        if (!isset($queryOptions->filtersDefinitions)) {
            $queryOptions->filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(
                $className,
                $depth
            );
        }
        if (!isset($queryOptions->orderByDefinitions)) {
            $queryOptions->orderByDefinitions = [];
            foreach ($queryOptions->filtersDefinitions->getElements() as $filtersDefinition) {
                $queryOptions->orderByDefinitions[] = $filtersDefinition->propertyName;
            }
        }
        self::$defaultQueryOptions[$className] = $queryOptions;
        return $queryOptions;
    }

    public static function setDefaultQueryOptions(AppliedQueryOptions $queryOptions)
    {
        $className = static::class;
        self::$defaultQueryOptions[$className] = $queryOptions;
    }


    /**
     * Expands current instance by options set in QueryOptions expand definitions
     * @return void
     */
    public function expand()
    {
        $propertiesToLazyLoad = static::getPropertiesToLazyLoad();
        $reflectionClass = ReflectionClass::instance(static::class);
        if (isset($this->queryOptions->expand)) {
            if ($this instanceof ObjectSet) {
                // in case of obejct set, we iterate through children and expand these
                foreach ($this->getElements() as $element) {
                    if (method_exists($element::class, 'getDefaultQueryOptions')) {
                        /** @var QueryOptionsTrait $element */
                        $element->setQueryOptions($this->queryOptions);
                        $element->expand();
                    }
                }
            }

            foreach ($this->queryOptions->expand->getElements() as $expandOption) {
                $propertyName = $expandOption->propertyName;
                if (!isset($propertiesToLazyLoad[$expandOption->propertyName]) && !isset($this->$propertyName)) {
                    continue;
                }

                $targetPropertyHasQueryOptions = false;
                $propertyType = $reflectionClass->getProperty($propertyName)->getType();
                $targetPropertyTypes = [];
                if ($propertyType instanceof ReflectionNamedType) {
                    $targetPropertyTypes[] = $propertyType;
                } elseif ($propertyType instanceof ReflectionUnionType) {
                    $targetPropertyTypes = $targetPropertyTypes + $propertyType->getTypes();
                }
                foreach ($targetPropertyTypes as $propertyType) {
                    /** @var QueryOptionsTrait $targetPropertyClass */
                    $targetPropertyClass = $propertyType->getName();
                    $targetPropertyReflectionClass = ReflectionClass::instance((string)$targetPropertyClass);
                    if ($targetPropertyReflectionClass && $targetPropertyReflectionClass->hasTrait(QueryOptionsTrait::class)) {
                        $targetPropertyHasQueryOptions = true;
                        /** @var AppliedQueryOptions $defaultQueryOptions */
                        // it can be that property is already loaded, in this case we still want to pass query options as it can be that we apply a recusrive expand and
                        // a subproperty of the property needs to be expanded.
                        $defaultQueryOptions = isset($this->$propertyName) ? $this?->$propertyName?->getQueryOptions(
                        ) : $targetPropertyClass::getDefaultQueryOptions();
                        $defaultQueryOptions->setQueryOptionsFromExpandOption($expandOption);
                        $targetPropertyClass::setDefaultQueryOptions($defaultQueryOptions);
                    }
                }
                $propertyAlreadyLoaded = isset($this->$propertyName);
                $loadedProperty = $this->$propertyName;
                // if the property has been already loaeded, we check if the expand option would influente the items,
                // e.g. if filters, orderBy or top and skip is set in the expand option, it is highly probable that the resulting object(set) is not the same
                // examples of this happening wihtout intention is, expand on Account with filter on subscriptions, and lazyloading ->settings before (which results in laoding subscriptions)
                // in this case the filter for the subscriptions expand is not applied anymore, this has to be avoided
                if ($propertyAlreadyLoaded && $this->$propertyName instanceof ObjectSet && (($expandOption?->filters ?? null) || ($expandOption?->orderByOptions ?? null) || ($expandOption?->top ?? null) || ($expandOption?->skip ?? null))) {
                    unset($this->$propertyName);
                    $loadedProperty = $this->$propertyName;
                }
                if ($loadedProperty && isset($expandOption->expandOptions) && $targetPropertyHasQueryOptions) {
                    /** @var QueryOptionsTrait $loadedProperty */
                    $loadedProperty->expand();
                }
            }
        }
    }
}