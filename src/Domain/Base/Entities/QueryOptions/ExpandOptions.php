<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadTrait;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionUnionType;
use ReflectionException;

/**
 * @property ExpandOption[] $elements
 * @method ExpandOption[] getElements()
 * @method ExpandOption first()
 * @method ExpandOption getByUniqueKey(string $uniqueKey)
 */
class ExpandOptions extends ObjectSet
{
    public static function fromString(string $expandQuery): ?ExpandOptions
    {
        if (empty($expandQuery)) {
            return null;
        }
        $expandOptions = new self();
        // match propertyName(parameters), propertyName, propertyName(parameters)
        preg_match_all(
            '/((?!=^|,)\s*(?P<propertyName>\w+)\s*(?P<expandParameters>\((?:[^()]+|(?&expandParameters))*\))?)+/',
            $expandQuery,
            $matches
        );
        //echo json_encode($matches);die();
        if (isset($matches['propertyName'])) {
            foreach ($matches['propertyName'] as $index => $propertyName) {
                $expandParameters = null;
                if ($matches['expandParameters'][$index] ?? null) {
                    // Parameters are enclosed in (), we remove them
                    preg_match(
                        '/^\((?P<expandParameters>.*)\)$/',
                        $matches['expandParameters'][$index],
                        $expandParameterMatches
                    );
                    if (isset($expandParameterMatches['expandParameters'])) {
                        $expandParameters = $expandParameterMatches['expandParameters'];
                    }
                }
                $expandOption = new ExpandOption($propertyName, $expandParameters);
                $expandOptions->add($expandOption);
            }
        }
        if (!$expandOptions->count()) {
            throw new BadRequestException(
                'Expand options are invalid, check e.g. count of opening and closing parentheses'
            );
        }
        return $expandOptions;
    }

    /**
     * Searches for ExpandOption with given propertyName and returns it if present
     * @param string $propertyName
     * @return ExpandOption|null
     */
    public function getExpandOptionByPropertyName(string $propertyName): ?ExpandOption
    {
        foreach ($this->getElements() as $element) {
            if ($element->propertyName == $propertyName) {
                return $element;
            }
        }
        return null;
    }

    /**
     * Validates all defined expand options if a LazyLoad definition exists for it in target class and throws Error if invalid field names are used.
     * returns true if validation finds no issues
     * @param FiltersDefinitions $allowedFields
     * @return bool
     * @throws BadRequestException
     */
    public function validateAgainstDefinitionsFromReferenceClass(string $referenceClassName): bool
    {
        $expandDefinitions = ExpandDefinitions::getExpandDefinitionsForReferenceClass($referenceClassName);
        foreach ($this->getElements() as $expandOption) {
            if (!($expandDefinition = $expandDefinitions->getExpandDefinitionByPropertyName(
                $expandOption->propertyName
            ))) {
                throw new BadRequestException(
                    "Property name used to expand ({$expandOption->propertyName}) is not allowed. Allowed field names are: [" . implode(
                        ', ',
                        array_map(function (ExpandDefinition $expandDefinition) {
                            return $expandDefinition->propertyName;
                        }, $expandDefinitions->getElements())
                    ) . ']'
                );
            }
            if (isset($expandOption->filters)) {
                if (!isset($expandDefinition->filtersDefinitions)) {
                    throw new BadRequestException(
                        "Expand property ({$expandOption->propertyName}) has no filters definitions"
                    );
                }
                $expandOption->filters->validateAgainstDefinitions($expandDefinition->filtersDefinitions);
            }
            if (isset($expandOption->orderByOptions)) {
                if (!isset($expandDefinition->orderByDefinitions)) {
                    throw new BadRequestException(
                        "Expand property ({$expandOption->propertyName}) has no orderBy definitions"
                    );
                }
                $expandOption->orderByOptions->validateAgainstDefinitions($expandDefinition->orderByDefinitions);
            }
            if (isset($expandOption->expandOptions)) {
                /** @var LazyLoadTrait $referenceClassName */
                $propertiesToLazyLoad = $referenceClassName::getPropertiesToLazyLoad();
                if (!isset($propertiesToLazyLoad[$expandOption->propertyName])) {
                    throw new BadRequestException(
                        "Expand property ({$expandOption->propertyName}) is not valid in {$referenceClassName} as property Name has no LazyLoad"
                    );
                }
                $reflectionClass = ReflectionClass::instance($referenceClassName);
                $reflectionProperty = $reflectionClass->getProperty($expandOption->propertyName);
                $propertyType = $reflectionProperty->getType();
                $targetPropertyTypes = [];
                if ($propertyType instanceof \ReflectionNamedType) {
                    $targetPropertyTypes[] = $propertyType;
                } elseif ($propertyType instanceof ReflectionUnionType) {
                    $targetPropertyTypes = $targetPropertyTypes + $propertyType->getTypes();
                }
                $targetPropertyHasQueryOptions = false;
                foreach($targetPropertyTypes as $propertyType){
                    $targetPropertyClass = $propertyType->getName();
                    if (is_a($targetPropertyClass,EntitySet::class, true)){
                        $targetPropertyClass = $targetPropertyClass::getEntityClass();
                    }
                    $targetPropertyReflectionClass = ReflectionClass::instance((string)$targetPropertyClass);
                    if ($targetPropertyReflectionClass->hasTrait(QueryOptionsTrait::class)) {
                        $targetPropertyHasQueryOptions = true;
                        /** @var QueryOptionsTrait $targetPropertyClass */
                        $targetPropertyClass::setDefaultQueryOptions($targetPropertyClass::getDefaultQueryOptions());
                        $expandOption->expandOptions->validateAgainstDefinitionsFromReferenceClass($targetPropertyClass);
                    }
                }
                if (!$targetPropertyHasQueryOptions){
                    throw new BadRequestException(
                        "Expand property ({$expandOption->propertyName}) is not valid in {$referenceClassName} as target class has no QueryOptions"
                    );
                }
            }
        }
        return true;
    }


    /**
     * Returns OPEN Api schmea definition regex
     * @return string
     */
    public function getRegexForOpenApi(): string
    {
        return '^(?P<expandOptions>\w+(\s*\(.+\))?)$';
    }

    public function uniqueKey(): string
    {
        $key = md5(json_encode($this->toObject(true, true)));
        return self::uniqueKeyStatic($key);
    }

    public function applyExpandOptionsToDoctrineQueryBuilder(
        DoctrineQueryBuilder &$queryBuilder,
        string $baseModelClass,
        ?string $baseModelAlias = null
    ): DoctrineQueryBuilder {
        /** @var DoctrineModel $baseModelClass */
        $modelReflectionClass = ReflectionClass::instance((string)$baseModelClass);
        $modelAlias = $baseModelAlias ?? $baseModelClass::MODEL_ALIAS;
        foreach ($this->getElements() as $expandOption) {
            try {
                $expandReflectionProperty = $modelReflectionClass->getProperty($expandOption->propertyName);
            } catch (ReflectionException $e) {
                $expandReflectionProperty = null;
            }
            if ($expandReflectionProperty) {
                $propertyName = $expandOption->propertyName;
                $join = "{$modelAlias}.$expandOption->propertyName";
                $queryBuilder->addSelect($expandOption->propertyName);
                $queryBuilder->leftJoin("{$modelAlias}.$expandOption->propertyName", $expandOption->propertyName);
                if (isset($expandOption->expandOptions)) {
                    // when property is a Collection, we need its Model class
                    $targetModel = $baseModelClass::getTargetModelClassForProperty($expandReflectionProperty);
                    if ($targetModel) {
                        $expandOption->expandOptions->applyExpandOptionsToDoctrineQueryBuilder(
                            $queryBuilder,
                            $targetModel,
                            $expandOption->propertyName
                        );
                    }
                }
            }
        }
        return $queryBuilder;
    }


}