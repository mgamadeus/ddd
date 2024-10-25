<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Reflection\ReflectionClass;
use ReflectionNamedType;

/**
 * Results paginationFilterAndSorting
 */
class ExpandDefinition extends ValueObject
{
    /** @var string The proeprty name to expand */
    public ?string $propertyName;

    /** @var string The name of the Class referenced */
    public ?string $referenceClass;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->propertyName);
    }

    public function getFiltersDefinitions(): ?FiltersDefinitions
    {
        /** @var QueryOptionsTrait $targetPropertyClass */
        if (!$targetPropertyClass = $this->getTargetPropertyClass()) {
            return null;
        }
        return $targetPropertyClass::getDefaultQueryOptions()->getFiltersDefinitions();
    }

    public function getOrderbyDefinitions(): ?array
    {
        /** @var QueryOptionsTrait $targetPropertyClass */
        if (!$targetPropertyClass = $this->getTargetPropertyClass()) {
            return null;
        }
        return $targetPropertyClass::getDefaultQueryOptions()->getOrderByDefinitions();
    }

    protected function getTargetPropertyClass(): ?string
    {
        if (!isset($this->referenceClass)) {
            return null;
        }
        $reflectionClass = ReflectionClass::instance($this->referenceClass);
        $reflectionProperty = $reflectionClass->getProperty($this->propertyName);
        $propertyType = $reflectionProperty->getType();
        if ($propertyType instanceof ReflectionNamedType) {
            /** @var QueryOptionsTrait $targetPropertyClass */
            $targetPropertyClass = $propertyType->getName();
            if (!$propertyType->isBuiltin()) {
                $targetPropertyReflectionClass = ReflectionClass::instance((string) $targetPropertyClass);
                if ($targetPropertyReflectionClass->hasTrait(QueryOptionsTrait::class)) {
                    /** @var string $targetPropertyClass */
                    return $targetPropertyClass;
                }
            }
        }
        return null;
    }
}