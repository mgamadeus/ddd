<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionNamedType;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use PHPUnit\TextUI\ReflectionException;
use ReflectionAttribute;
use ReflectionUnionType;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * @property FiltersDefinition[] $elements
 * @method FiltersDefinition[] getElements()
 * @method FiltersDefinition first()
 * @method FiltersDefinition getByUniqueKey(string $uniqueKey)
 */
class FiltersDefinitions extends ObjectSet
{
    /** @var FiltersDefinitions[] */
    protected static ?array $filtersDefinitionsForClass = [];

    /**
     * Allowed filters either as string representing allowed property name or
     * array representing on it's first index the property name and following allwed options to be used as value
     * @param string|array ...$allowedPropertyNames
     */
    public function __construct(string|array ...$allowedPropertyNames)
    {
        foreach ($allowedPropertyNames as $allowedPropertyName) {
            if (is_array($allowedPropertyName)) {
                $allowedPropertyName = new FiltersDefinition(
                    $allowedPropertyName[0],
                    array_slice($allowedPropertyName, 1)
                );
                $this->add($allowedPropertyName);
            } else {
                $allowedPropertyName = new FiltersDefinition($allowedPropertyName);
                $this->add($allowedPropertyName);
            }
        }
        parent::__construct();
    }

    /**
     * Reteurns FiltersDefinitions based on EntitySet elements class content
     * @param string $referenceClassName
     * @return FiltersDefinitions
     */
    public static function getFiltersDefinitionsForReferenceClass(
        string $referenceClassName,
    ): ?FiltersDefinitions {
        if (self::$filtersDefinitionsForClass[$referenceClassName] ?? null) {
            return self::$filtersDefinitionsForClass[$referenceClassName];
        }
        $filtersDefinitions = new FiltersDefinitions();
        // we only generate filters for all property names if the class has a Lazyload repo of type DB
        /** @var Entity $referenceClassName */
        $repoClass = $referenceClassName::getRepoClass(LazyLoadRepo::DB);
        $reflectionClass = ReflectionClass::instance($referenceClassName);
        if ($repoClass) {
            $filtersProperties = self::getFilterPropertiesForClass($referenceClassName);
            if ($filtersProperties) {
                foreach ($filtersProperties as $filterPropertyName => $options) {
                    $filterDefinition = new FiltersDefinition(
                        $filterPropertyName,
                        is_array($options) ? $options : null
                    );
                    $filtersDefinitions->add($filterDefinition);
                }
            }
            /*
            $expandDefinitions = ExpandDefinitions::getExpandDefinitionsForReferenceClass(
                $referenceClassName,
                $depth
            );

            foreach ($expandDefinitions->getElements() as $expandDefinition) {
                // check property of expand definition
                if ($expandDefinition->filtersDefinitions ?? null) {
                    foreach ($expandDefinition->filtersDefinitions->getElements() as $filtersDefinition) {
                        $filterDefinition = new FiltersDefinition(
                            $expandDefinition->propertyName . '.' . $filtersDefinition->propertyName,
                            $filtersDefinition->options ?? null,
                            $expandDefinition
                        );
                        $filtersDefinitions->add($filterDefinition);

                        $filtersProperties[$expandDefinition->propertyName . '.' . $filtersDefinition->propertyName] = $filtersDefinition->options ?? true;
                    }
                }
            }*/
        }
        self::$filtersDefinitionsForClass[$referenceClassName] = $filtersDefinitions;
        return $filtersDefinitions;
    }

    /**
     * Recursively determines filter options as associative array by option property name e.g. ['firstName'=>true] and in case of filter having options,
     * ['status'=>['active','inactive']]] including the options as well
     * @param string $className
     * @param string $propertyPrefix
     * @param array $callPath
     * @return array
     * @throws ReflectionException
     */
    private static function getFilterPropertiesForClass(
        string $className,
        string $propertyPrefix = '',
        array $callPath = []
    ): array {
        if (isset($callPath[$className])) {
            return [];
        }
        $callPath[$className] = true;
        $reflectionClass = ReflectionClass::instance($className);
        $elementsToSkip = ['queryOptions' => true, 'objectType' => true];
        $allowedFilterProperties = [];
        if (is_a($className, EntitySet::class, true)) {
            $arrayType = $reflectionClass->getProperty('elements')->getType()->getArrayType();
            if ($arrayType instanceof ReflectionNamedType) {
                $elementTypeClass = $arrayType->getName();
                if (is_a($elementTypeClass, DefaultObject::class, true)) {
                    return self::getFilterPropertiesForClass($elementTypeClass, $propertyPrefix, $callPath);
                }
            }
            // if we have multiple types as possible array elements,
            // e.g. in case of an EntitySet containing classes involved in single table inheritance scheme
            // like Post, Event, etc. we need to include all properties from all possible classes
            elseif ($arrayType instanceof ReflectionUnionType) {
                foreach ($arrayType->getTypes() as $possibleType){
                    $elementTypeClass = $possibleType->getName();
                    if (is_a($elementTypeClass, DefaultObject::class, true)) {
                        $allowedFilterPropertiesForPossibleType = self::getFilterPropertiesForClass($elementTypeClass, $propertyPrefix, $callPath);
                        $allowedFilterProperties = array_merge($allowedFilterProperties, $allowedFilterPropertiesForPossibleType);
                    }
                }
                return $allowedFilterPropertiesForPossibleType;
            }
            return [];
        }
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            $type = null;
            if ($reflectionProperty->getType() instanceof ReflectionUnionType) {
                foreach ($reflectionProperty->getType()->getTypes() as $unionType) {
                    if ($unionType) {
                        $type = $unionType;
                        break;
                    }
                }
            } else {
                $type = $reflectionProperty->getType();
            }
            if (!$type) {
                continue;
            }
            if (isset($elementsToSkip[$reflectionProperty->getName()])) {
                continue;
            }
            if ($type->isBuiltin() || (is_a($type->getName(), DateTime::class, true))) {
                if ($choiceAttribute = $reflectionProperty->getAttributes(
                    Choice::class,
                    ReflectionAttribute::IS_INSTANCEOF
                )[0] ?? null) {
                    /** @var Choice $choiceAttributeInstance */
                    $choiceAttributeInstance = $choiceAttribute->newInstance();
                    $allowedFilterProperties[$propertyPrefix . $reflectionProperty->getName(
                    )] = $choiceAttributeInstance->choices;
                } else {
                    $allowedFilterProperties[$propertyPrefix . $reflectionProperty->getName()] = true;
                }
            }
            $subObjectFilters = [];
            // for properties, we do not include ObjectSets in filter options
            if ($type instanceof \ReflectionNamedType) {
                if (is_a(
                    $type->getName(),
                    ChangeHistory::class,
                    true
                )) {
                    /** @var ChangeHistory $changeHistoryAttributeInstance */
                    $changeHistoryAttributeInstance = $className::getChangeHistoryAttribute(true);
                    $createdColumn = $changeHistoryAttributeInstance?->getCreatedColumn();
                    $modifiedColumn = $changeHistoryAttributeInstance?->getModifiedColumn();
                    if ($createdColumn) {
                        $allowedFilterProperties[$propertyPrefix . $createdColumn] = true;
                    }
                    if ($modifiedColumn) {
                        $allowedFilterProperties[$propertyPrefix . $modifiedColumn] = true;
                    }
                }
                elseif (DefaultObject::isValueObject($type->getName()) && !is_a(
                        $type->getName(),
                        ObjectSet::class,
                        true
                    )) {
                    $subObjectFilters = self::getFilterPropertiesForClass(
                        $type->getName(),
                        $propertyPrefix . $reflectionProperty->getName() . '.',
                        $callPath
                    );
                    if ($subObjectFilters == [1]) {
                        die($className);
                    }
                } elseif (DefaultObject::isEntity($type)) {
                    // we do not add lazyloaded Entities as filters
                    if ($lazyloadAttribute = $reflectionProperty->getAttributes(LazyLoad::class)[0] ?? null) {
                        continue;
                    }
                    $subObjectFilters = self::getFilterPropertiesForClass(
                        $type->getName(),
                        $propertyPrefix . $reflectionProperty->getName() . '.',
                        $callPath
                    );
                }
            }
            foreach ($subObjectFilters as $subObjectFilter => $details) {
                $allowedFilterProperties[$subObjectFilter] = $details;
            }
        }
        return $allowedFilterProperties;
    }

    public function getFilterDefinitionForPropertyName(string $propertyName): ?FiltersDefinition
    {
        return $this->getByUniqueKey($propertyName);
    }
}