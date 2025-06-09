<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\MethodNotAllowedException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use ReflectionException;
use ReflectionNamedType;

/**
 * Results paginationFilterAndSorting
 */
class ExpandDefinition extends ValueObject
{
    /** @var string The proeprty name to expand */
    public ?string $propertyName;

    /** @var string The name of the Class with the reference */
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

    public function getTargetPropertyClass(): ?string
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
                $targetPropertyReflectionClass = ReflectionClass::instance((string)$targetPropertyClass);
                if ($targetPropertyReflectionClass->hasTrait(QueryOptionsTrait::class)) {
                    /** @var string $targetPropertyClass */
                    return $targetPropertyClass;
                }
                throw new MethodNotAllowedException(
                    "Cannot use target property class {$targetPropertyReflectionClass->getName()} of property {$this->propertyName} as target property class of ExpandOption as it has no QueryOptions trait."
                );
            }
        }
        return null;
    }

    public function getOrderbyDefinitions(): ?array
    {
        /** @var QueryOptionsTrait $targetPropertyClass */
        if (!$targetPropertyClass = $this->getTargetPropertyClass()) {
            return null;
        }
        return $targetPropertyClass::getDefaultQueryOptions()->getOrderByDefinitions();
    }

    /**
     * Returns DB Repo for Reference Class
     * @return string|null
     */
    public function getReferenceClassRepo(): ?string
    {
        if (empty($this->referenceClass)) {
            return null;
        }
        $reflectionClass = ReflectionClass::instance($this->referenceClass);
        if (!$reflectionClass->hasTrait(QueryOptionsTrait::class)) {
            throw new MethodNotAllowedException("Cannot use class {$this->referenceClass} as reference class of ExpandOption as it has no QueryOptions trait.");
        }

        /** @var DefaultObject $referenceClass */
        $referenceClass = $this->referenceClass;
        return $referenceClass::getRepoClass(LazyLoadRepo::DB);
    }

    /**
     * Returns DB Repo for target property in Reference Class
     * @param string $propertyName
     * @return string|null
     * @throws ReflectionException
     */
    public function getTargetPropertyRepoClass(string $propertyName): ?string
    {
        /** @var DefaultObject $targetPropertyClass */
        $targetPropertyClass = $this->getTargetPropertyClass();
        if (!$targetPropertyClass) {
            return null;
        }
        return $targetPropertyClass::getRepoClass(LazyLoadRepo::DB);
    }
}