<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadTrait;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionUnionType;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptionsTrait;
use Doctrine\ORM\Query\Expr\Join;
use ReflectionNamedType;

/**
 * @property ExpandOption[] $elements
 * @method ExpandOption[] getElements()
 * @method ExpandOption first()
 * @method ExpandOption getByUniqueKey(string $uniqueKey)
 * @method ExpandOption|AppliedQueryOptions|DtoQueryOptionsTrait getParent()
 * @property ExpandOption|AppliedQueryOptions|DtoQueryOptionsTrait $parent
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
            if (
                !($expandDefinition = $expandDefinitions->getExpandDefinitionByPropertyName(
                    $expandOption->propertyName
                ))
            ) {
                throw new BadRequestException(
                    "Property name used to expand ({$expandOption->propertyName}) is not allowed. Allowed field names are: [" . implode(
                        ', ',
                        array_map(function (ExpandDefinition $expandDefinition) {
                            return $expandDefinition->propertyName;
                        }, $expandDefinitions->getElements())
                    ) . ']'
                );
            }
            $expandOption->expandDefinition = $expandDefinition;
            if (isset($expandOption->filters)) {
                if (!$expandDefinition->getFiltersDefinitions()) {
                    throw new BadRequestException(
                        "Expand property ({$expandOption->propertyName}) has no filters definitions"
                    );
                }
                $expandOption->filters->validateAgainstDefinitions($expandDefinition->getFiltersDefinitions());
            }
            if (isset($expandOption->orderByOptions)) {
                if (!$expandDefinition->getOrderbyDefinitions()) {
                    throw new BadRequestException(
                        "Expand property ({$expandOption->propertyName}) has no orderBy definitions"
                    );
                }
                $expandOption->orderByOptions->validateAgainstDefinitions($expandDefinition->getOrderbyDefinitions());
            }
            if (isset($expandOption->expandOptions)) {
                /** @var LazyLoadTrait $propertyReferenceClassName */
                $propertyReferenceClassName = $expandDefinition->referenceClass;
                $propertiesToLazyLoad = $propertyReferenceClassName::getPropertiesToLazyLoad();
                if (!isset($propertiesToLazyLoad[$expandOption->propertyName])) {
                    throw new BadRequestException(
                        "Expand property ({$expandOption->propertyName}) is not valid in {$referenceClassName} as property Name has no LazyLoad"
                    );
                }
                /** @var string $propertyReferenceClassName */
                $reflectionClass = ReflectionClass::instance($propertyReferenceClassName);
                $reflectionProperty = $reflectionClass->getProperty($expandOption->propertyName);
                $propertyType = $reflectionProperty->getType();
                $targetPropertyTypes = [];
                if ($propertyType instanceof ReflectionNamedType) {
                    $targetPropertyTypes[] = $propertyType;
                } elseif ($propertyType instanceof ReflectionUnionType) {
                    $targetPropertyTypes = $targetPropertyTypes + $propertyType->getTypes();
                }
                $targetPropertyHasQueryOptions = false;
                foreach ($targetPropertyTypes as $propertyType) {
                    $targetPropertyClass = $propertyType->getName();
                    $targetPropertyReflectionClass = ReflectionClass::instance((string)$targetPropertyClass);
                    $validationError = null;
                    try {
                        if ($targetPropertyReflectionClass->hasTrait(QueryOptionsTrait::class)) {
                            $targetPropertyHasQueryOptions = true;
                            /** @var QueryOptionsTrait $targetPropertyClass */
                            $targetPropertyClass::setDefaultQueryOptions(
                                $targetPropertyClass::getDefaultQueryOptions()
                            );
                            $expandOption->expandOptions->validateAgainstDefinitionsFromReferenceClass(
                                $targetPropertyClass
                            );
                        }
                    } catch (BadRequestException $e) {
                        $validationError = $e;
                    }
                    // If we have an EntitySet, we also consider the particular Entity classes from its elements
                    if ($validationError && is_a($targetPropertyClass, EntitySet::class, true)) {
                        $targetPropertyClass = $targetPropertyClass::getEntityClass();
                        $targetPropertyReflectionClass = ReflectionClass::instance((string)$targetPropertyClass);
                        if ($targetPropertyReflectionClass->hasTrait(QueryOptionsTrait::class)) {
                            $targetPropertyHasQueryOptions = true;
                            /** @var QueryOptionsTrait $targetPropertyClass */
                            $targetPropertyClass::setDefaultQueryOptions($targetPropertyClass::getDefaultQueryOptions());
                            $expandOption->expandOptions->validateAgainstDefinitionsFromReferenceClass(
                                $targetPropertyClass
                            );
                        }
                    } elseif ($validationError) {
                        throw $validationError;
                    }
                }
                if (!$targetPropertyHasQueryOptions) {
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
    ): DoctrineQueryBuilder {
        foreach ($this->getElements() as $expandOption) {
            /** @var DoctrineModel $baseModelClass */
            $baseModelClass = $expandOption->getBaseModelClass();
            $baseModelAlias = $expandOption->getBaseModelAlias();
            // The base model from which all joins start
            $rootModelAlias = $expandOption->getRootModelAlias();
            // The parent join alias that will be used to build the next join
            $parentJoinAlias = $expandOption->getParentJoinAlias();

            $targetPropertyModelClass = $expandOption->getTargetPropertyModelClass();
            if (!($baseModelClass && $baseModelAlias && $targetPropertyModelClass)) {
                continue;
            }

            $propertyName = $expandOption->propertyName;
            $joinAlreadyExists = false;
            $existingJoins = $queryBuilder->getDQLPart('join');
            $joinString = "{$parentJoinAlias}.$propertyName";

            // Check if the join for the current alias already exists
            if (!empty($existingJoins) && isset($existingJoins[$rootModelAlias])) {
                foreach ($existingJoins[$rootModelAlias] as $joinPart) {
                    /** @var Join $joinPart */
                    if (!$joinPart instanceof Join) {
                        continue;
                    }
                    if (
                        $joinPart->getJoin() == $joinString
                    ) {
                        $joinAlreadyExists = true;
                        // we have to use the already existing alias instead of creating a dynamic one
                        $expandOption->joinAlias = $joinPart->getAlias();
                        break;
                    }
                }
            }
            if (!$joinAlreadyExists) {
                $expandOption->joinAlias = $expandOption->constructJoinModelAliasRecursively();
            }
            $queryBuilder->addSelect($expandOption->joinAlias);
            if (!$joinAlreadyExists) {
                $queryBuilder->leftJoin($joinString, $expandOption->joinAlias);
            }
            // Apply nested select options to the joined entity, if provided
            if (isset($expandOption->selectOptions)) {
                $expandOption->selectOptions->applySelectToDoctrineQueryBuilder(
                    queryBuilder: $queryBuilder,
                    baseModelClass: $targetPropertyModelClass,
                    baseModelAlias: $expandOption->joinAlias
                );
            }
            if (isset($expandOption->filters)) {
                $expandOption->filters->applyFiltersToJoin(
                    queryBuilder: $queryBuilder,
                    rootModelAlias: $rootModelAlias,
                    joinString: $joinString,
                    joinAlias: $expandOption->joinAlias,
                );
            }
            if (isset($expandOption->expandOptions)) {
                // when property is a Collection, we need its Model class
                //$targetModel = $baseModelClass::getTargetModelClassForProperty($expandReflectionProperty);
                $expandOption->expandOptions->applyExpandOptionsToDoctrineQueryBuilder(
                    $queryBuilder
                );
            }
            /** @var DBEntity $targetPropertyRepoClass */
            $targetPropertyRepoClass = $expandOption->getTargetPropertyRepoClass();
            if ($targetPropertyRepoClass && method_exists($targetPropertyRepoClass, 'applyReadRightsQuery')) {
                /** @var DBEntity $baseRepoClass */
                $rightsQueryBuilder = $targetPropertyRepoClass::createQueryBuilder();
                $null = null;
                $readRightsFiltersApplied = $targetPropertyRepoClass::applyReadRightsQuery($rightsQueryBuilder);
                $targetPropertyModelAlias = $targetPropertyRepoClass::getBaseModelAlias();

                if ($readRightsFiltersApplied) {
                    $this->applyConditionsFromReadRightsQueryBuilder($queryBuilder, $rightsQueryBuilder, $targetPropertyModelAlias, $expandOption->joinAlias);
                    // extract all relevant where, join etc clauses from the $tempQueryBuilder and apply them to the main query builder
                }
            }
        }
        return $queryBuilder;
    }

    /**
     * Copies all WHERE, JOIN, and parameter constraints from $rightsQueryBuilder to $mainQueryBuilder,
     * adjusting the table alias from $tempBaseAlias to $targetJoinAlias
     * and reindexing named/numeric parameters to avoid collisions.
     *
     * @param DoctrineQueryBuilder $mainQueryBuilder
     * @param DoctrineQueryBuilder $rightsQueryBuilder
     * @param string $tempBaseAlias e.g. "dbaccount" from DBAccount::getBaseModelAlias()
     * @param string $targetJoinAlias e.g. $expandOption->joinAlias
     */
    public function applyConditionsFromReadRightsQueryBuilder(
        DoctrineQueryBuilder $mainQueryBuilder,
        DoctrineQueryBuilder $rightsQueryBuilder,
        string $tempBaseAlias,
        string $targetJoinAlias
    ): void {
        // Extract WHERE part from the rights query builder
        $wherePart = $rightsQueryBuilder->getDQLPart('where');
        $whereString = null;
        if ($wherePart) {
            // cast WHERE expression to string and swap table alias
            $whereString = (string)$wherePart;
            $whereString = str_replace($tempBaseAlias . '.', $targetJoinAlias . '.', $whereString);

            // reindex and apply parameters, get back the adjusted SQL
            $whereString = $this->reindexPlaceholdersAndApplyParameters(
                $whereString,
                $mainQueryBuilder,
                $rightsQueryBuilder->getParameters()
            );
            // apply the modified WHERE to the main query builder
            $mainQueryBuilder->andWhere($whereString);
        }

        // 2) LEFT JOINs
        $allJoins = $rightsQueryBuilder->getDQLPart('join')[$tempBaseAlias] ?? [];
        foreach ($allJoins as $joinObject) {
            /** @var Join $joinObject */
            $joinExpression = str_replace($tempBaseAlias . '.', $targetJoinAlias . '.', $joinObject->getJoin());

            $joinCondition = $joinObject->getCondition();
            if ($joinCondition) {
                $joinCondition = str_replace($tempBaseAlias . '.', $targetJoinAlias . '.', $joinCondition);
                $joinCondition = $this->reindexPlaceholdersAndApplyParameters($joinCondition, $mainQueryBuilder, $rightsQueryBuilder->getParameters());
            }

            $mainQueryBuilder->leftJoin($joinExpression, $joinObject->getAlias(), $joinObject->getConditionType(), $joinCondition ?: null);
        }
    }

    protected function reindexPlaceholdersAndApplyParameters(
        string $dqlFragment,
        DoctrineQueryBuilder $mainQueryBuilder,
        iterable $rightsParameters
    ): string {
        // replace params by indexed ones and apply if found in where string
        foreach ($rightsParameters as $rightsParam) {
            $paramPrefix = is_numeric($rightsParam->getName()) ? '?' : ':';
            $pattern = '/' . preg_quote($paramPrefix . $rightsParam->getName(), '/') . '(?=\W|$)/';
            if (preg_match($pattern, $dqlFragment)) {
                $paramIndex = $mainQueryBuilder->getParameters()->count() + 1;
                $dqlFragment = preg_replace($pattern, '?' . $paramIndex, $dqlFragment);
                $mainQueryBuilder->setParameter($paramIndex, $rightsParam->getValue(), $rightsParam->getType());
            }
        }
        return $dqlFragment;
    }
}