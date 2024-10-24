<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Reflection\ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * @property ExpandDefinition[] $elements
 * @method ExpandDefinition[] getElements()
 * @method ExpandDefinition first()
 * @method ExpandDefinition getByUniqueKey(string $uniqueKey)
 */
class ExpandDefinitions extends ObjectSet
{
    /** @var ExpandDefinitions[] */
    protected static array $expandDefinitionsForClass = [];

    /**
     * Returns LazyLoad Properties of reference Class name as Expand options
     * @param string $referenceClassName
     * @return ExpandDefinitions
     * @throws ReflectionException
     */
    public static function getExpandDefinitionsForReferenceClass(
        string $referenceClassName,
        $depth = 1
    ): ExpandDefinitions {
        if ($depth < 0) {
            return new ExpandDefinitions();
        }
        if (isset(self::$expandDefinitionsForClass[$referenceClassName])) {
            return self::$expandDefinitionsForClass[$referenceClassName];
        }
        $reflectionClass = ReflectionClass::instance($referenceClassName);

        $expandOnParentElements = Config::getEnv('QUERY_OPTIONS_EXPAND_ON_PARENT_ELEMENTS') ?? false;
        $expandDefinitions = new ExpandDefinitions();
        $referenceClassName = $reflectionClass->getName();

        /** @var Entity $referenceClassName */
        $propertiesToLazyLoad = $referenceClassName::getPropertiesToLazyLoad();
        $propertiesToLazyLoadWithDetails = [];
        foreach ($propertiesToLazyLoad as $propertyName => $lazyLoadDefinitions) {
            $reflectionProperty = $reflectionClass->getProperty($propertyName);
            $propertiesToLazyLoadWithDetails[$propertyName] = [
                'class' => $referenceClassName,
                'propertyName' => $propertyName,
                'property' => $reflectionProperty,
                'lazyLoadDefinitions' => $lazyLoadDefinitions
            ];
        }
        // in case of EntitySets we also add Expand possibilities of their element classes
        if (is_a($referenceClassName, ObjectSet::class, true)) {
            $arrayType = $reflectionClass->getProperty('elements')->getType()->getArrayType();

            // in cases of Union Types, e.g. Posts can consist of Event or Post, we first try to find the common ancestor of them
            $arrayTypes = [];
            if ($arrayType instanceof ReflectionUnionType) {
                $arrayTypes = $arrayType->getTypes();
            } elseif ($arrayType instanceof ReflectionNamedType) {
                $arrayTypes[] = $arrayType;
            }
            foreach ($arrayTypes as $arrayType) {
                $elementTypeClass = $arrayType->getName();
                if (is_a($elementTypeClass, DefaultObject::class, true)) {
                    $elementPropertiesToLazyLoad = $elementTypeClass::getPropertiesToLazyLoad();
                    $elementReflectionClass = ReflectionClass::instance($elementTypeClass);
                    foreach ($elementPropertiesToLazyLoad as $propertyName => $lazyLoadDefinitions) {
                        $reflectionProperty = $elementReflectionClass->getProperty($propertyName);
                        $propertiesToLazyLoadWithDetails[$propertyName] = [
                            'class' => $elementTypeClass,
                            'propertyName' => $propertyName,
                            'property' => $reflectionProperty,
                            'lazyLoadDefinitions' => $lazyLoadDefinitions
                        ];
                    }
                }
            }
        }
        foreach ($propertiesToLazyLoadWithDetails as $propertyToLazyLoad) {
            /** @var LazyLoad[] $lazyLoadDefinitions */
            $lazyLoadDefinitions = $propertyToLazyLoad['lazyLoadDefinitions'];
            // we do not want to allow expand on parent objects
            $skipProperty = false;
            foreach ($lazyLoadDefinitions as $repoType => $lazyLoadDefinition) {
                if ($lazyLoadDefinition->addAsParent && !$expandOnParentElements) {
                    $skipProperty = true;
                    break;
                }
            }
            if ($skipProperty) {
                continue;
            }

            $propertyName = $propertyToLazyLoad['propertyName'];
            $propertyType = $propertyToLazyLoad['property']->getType();
            $expandDefinition = new ExpandDefinition();
            $expandDefinition->propertyName = $propertyName;
            $expandDefinition->referenceClass = $propertyToLazyLoad['class'];
            if ($propertyType instanceof ReflectionNamedType) {
                /** @var QueryOptionsTrait $targetPropertyClass */
                $targetPropertyClass = $propertyType->getName();
                if (!$propertyType->isBuiltin()) {
                    $targetPropertyReflectionClass = ReflectionClass::instance((string)$targetPropertyClass);
                    if ($targetPropertyReflectionClass->hasTrait(QueryOptionsTrait::class)) {
                        /** @var QueryOptions $defaultQueryOptions */
                        $defaultQueryOptions = $targetPropertyClass::getDefaultQueryOptions($depth);
                        if (isset($defaultQueryOptions->filtersDefinitions)) {
                            $expandDefinition->filtersDefinitions = $defaultQueryOptions->filtersDefinitions;
                        }
                        if (isset($defaultQueryOptions->orderByDefinitions)) {
                            $expandDefinition->orderByDefinitions = $defaultQueryOptions->orderByDefinitions;
                        }
                    }
                }
            }
            $expandDefinitions->add($expandDefinition);
        }
        self::$expandDefinitionsForClass[$referenceClassName] = $expandDefinitions;
        return $expandDefinitions;
    }

    /**
     * @param string $propertyName
     * @return ExpandDefinition|null
     */
    public function getExpandDefinitionByPropertyName(string $propertyName): ?ExpandDefinition
    {
        $uniqueKey = ExpandDefinition::uniqueKeyStatic($propertyName);
        return $this->getByUniqueKey($uniqueKey);
    }
}