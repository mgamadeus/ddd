<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\DefaultObject;
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

    protected static $defaultQueryOptions = [];
    /** @var AppliedQueryOptions Applied Query Options */
    protected AppliedQueryOptions $queryOptions;

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
        $queryOptions = new AppliedQueryOptions($queryOptionsAttributeInstance, $className);
        self::$defaultQueryOptions[$className] = $queryOptions;
        return $queryOptions;
    }

    public static function setDefaultQueryOptions(AppliedQueryOptions $queryOptions)
    {
        $className = static::class;
        self::$defaultQueryOptions[$className] = $queryOptions;
    }

    /**
     * Expands current instance by options set in QueryOptions expand definitions.
     *
     * Default semantics is "fill in the gaps": properties that are already loaded
     * (via eager fetch in DBEntitySet::find -> applyExpandOptionsToDoctrineQueryBuilder
     * or via a previous lazy-load) are left untouched. Recursion descends into them
     * to resolve any not-yet-loaded sub-properties — including non-DB lazy types
     * like CLASS_METHOD or VIRTUAL (e.g. SupportMessageAttachment.authJWTPayload),
     * which can't be served by the eager DB fetch and must be filled in per element.
     *
     * @param bool $reloadAlreadyLoadedSetsOnScopedExpand Opt-in legacy behavior:
     *   when true and an already-loaded ObjectSet's expand option carries scope
     *   (filters/orderBy/top/skip), the cached set is unset and a fresh lazy-load
     *   is triggered with the expand-scoped QueryOptions. Useful when a previous
     *   lazy-load with different scope populated the property and the caller now
     *   wants the expand's scope applied. Default false — preserves eager-fetch
     *   work and is the right choice when the caller eager-fetched via the
     *   service-level find delegation. The flag propagates to recursive expand()
     *   calls so the choice is consistent across the entity tree.
     * @return void
     */
    public function expand(bool $reloadAlreadyLoadedSetsOnScopedExpand = false)
    {
        $propertiesToLazyLoad = static::getPropertiesToLazyLoad();
        $reflectionClass = ReflectionClass::instance(static::class);
        if (isset($this->queryOptions->expand)) {
            if ($this instanceof ObjectSet) {
                // in case of obejct set, we iterate through children and expand these
                // first we determine the possible Element types and check if there are expand properties suited to possible ELement expand Options
                $reflectionClass = $this->getReflectionClass();
                $elementsReflectionProperty = $reflectionClass->getProperty('elements');
                $elementsReflectionPropertyType = $elementsReflectionProperty->getType()->getArrayType();
                $elementsPossibleLazyLoadProperties = [];
                $elementPropertyTypes = [];
                if ($elementsReflectionPropertyType instanceof ReflectionNamedType) {
                    $elementPropertyTypes[] = $elementsReflectionPropertyType->getName();
                } elseif ($elementsReflectionPropertyType instanceof ReflectionUnionType) {
                    foreach ($elementsReflectionPropertyType->getTypes() as $propertyType) {
                        $elementPropertyTypes[] = $propertyType->getName();
                    }
                }
                foreach ($elementPropertyTypes as $elementPropertyType) {
                    /** @var DefaultObject $elementClassName */
                    $elementsPossibleLazyLoadProperties += $elementPropertyType::getPropertiesToLazyLoad();
                }
                $hasPropertyToExpand = false;
                foreach ($this->queryOptions->expand->getElements() as $expandOption) {
                    if (isset($elementsPossibleLazyLoadProperties[$expandOption->propertyName])) {
                        $hasPropertyToExpand = true;
                        break;
                    }
                }
                if ($hasPropertyToExpand) {
                    foreach ($this->getElements() as $element) {
                        if (method_exists($element::class, 'getDefaultQueryOptions')) {
                            /** @var QueryOptionsTrait $element */
                            $element->setQueryOptions($this->queryOptions);
                            $element->expand($reloadAlreadyLoadedSetsOnScopedExpand);
                        }
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
                    if (
                        $targetPropertyReflectionClass && $targetPropertyReflectionClass->hasTrait(
                            QueryOptionsTrait::class
                        )
                    ) {
                        $targetPropertyHasQueryOptions = true;
                        /** @var AppliedQueryOptions $defaultQueryOptions */ // it can be that property is already loaded, in this case we still want to pass query options as it can be that we apply a recusrive expand and
                        // a subproperty of the property needs to be expanded.
                        $defaultQueryOptions = isset($this->$propertyName) ? $this?->$propertyName?->getQueryOptions(
                        ) : $targetPropertyClass::getDefaultQueryOptions();
                        $defaultQueryOptions->setQueryOptionsFromExpandOption($expandOption);
                        $targetPropertyClass::setDefaultQueryOptions($defaultQueryOptions);
                    }
                }
                $propertyAlreadyLoaded = isset($this->$propertyName);
                $loadedProperty = $this->$propertyName;
                // if property has been loaded from cache, we need to apply expand options to it
                if (isset($loadedProperty) && is_object($loadedProperty)) {
                    $targetPropertyReflectionClass = ReflectionClass::instance($loadedProperty::class);
                    if (
                        $targetPropertyReflectionClass && $targetPropertyReflectionClass->hasTrait(
                            QueryOptionsTrait::class
                        )
                    ) {
                        /** @var QueryOptionsTrait $loadedProperty */
                        $loadedProperty->getQueryOptions()->setQueryOptionsFromExpandOption($expandOption);
                    }
                }

                // Opt-in legacy reload: if the property has been already loaded, we check if the
                // expand option would influence the items — e.g. filters/orderBy/top/skip on the
                // expand could imply a different result set than what's currently cached. Example
                // of unintended drift: expand on Account with filter on subscriptions, and a
                // previous lazyload of ->settings already populated subscriptions; without the
                // reload the new filter would be silently ignored.
                //
                // This branch is now opt-in via $reloadAlreadyLoadedSetsOnScopedExpand. Default
                // behavior is fill-in-the-gaps: keep the already-loaded set and only descend
                // recursively to resolve sub-properties that aren't loaded yet (typical case
                // when the parent was eager-fetched via the Set-Find delegation in
                // EntitiesService::find — a re-fetch here would discard that work).
                if ($reloadAlreadyLoadedSetsOnScopedExpand
                    && $propertyAlreadyLoaded
                    && $this->$propertyName instanceof ObjectSet
                    && (($expandOption?->filters ?? null) || ($expandOption?->orderByOptions ?? null) || ($expandOption?->top ?? null) || ($expandOption?->skip ?? null))) {
                    unset($this->$propertyName);
                    $loadedProperty = $this->$propertyName;
                }
                if ($loadedProperty && isset($expandOption->expandOptions) && $targetPropertyHasQueryOptions) {
                    /** @var QueryOptionsTrait $loadedProperty */
                    $loadedProperty->expand($reloadAlreadyLoadedSetsOnScopedExpand);
                }
            }
        }
    }
}