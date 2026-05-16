<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadTrait;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
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
     * @param bool $recursive
     * @return ExpandOption|null
     */
    public function getExpandOptionByPropertyName(string $propertyName, bool $recursive = false): ?ExpandOption
    {
        if ($recursive && ($currPontPos = strpos($propertyName, '.')) !== false) {
            $firstSegment = substr($propertyName, 0, $currPontPos);
            $remainingSegments = substr($propertyName, $currPontPos + 1);
            $firstSegmentOption = $this->getExpandOptionByPropertyName($firstSegment, false);
            if (!$firstSegmentOption || !isset($firstSegmentOption->expandOptions)) {
                return null;
            }
            return $firstSegmentOption->expandOptions->getExpandOptionByPropertyName($remainingSegments, true);
        }
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
                    "Property name used to expand ($expandOption->propertyName) is not allowed. Allowed field names are: [" . implode(
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
                        "Expand property ($expandOption->propertyName) has no filters definitions"
                    );
                }
                $expandOption->filters->validateAgainstDefinitions($expandDefinition->getFiltersDefinitions());
            }
            if (isset($expandOption->orderByOptions)) {
                if (!$expandDefinition->getOrderbyDefinitions()) {
                    throw new BadRequestException(
                        "Expand property ($expandOption->propertyName) has no orderBy definitions"
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
                        "Expand property ($expandOption->propertyName) is not valid in $referenceClassName as property Name has no LazyLoad"
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
                    $targetPropertyReflectionClass = ReflectionClass::instance($targetPropertyClass);
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
                        $targetPropertyReflectionClass = ReflectionClass::instance($targetPropertyClass);
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
                        "Expand property ($expandOption->propertyName) is not valid in $referenceClassName as target class has no QueryOptions"
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
            $joinString = "$parentJoinAlias.$propertyName";

            // Check if a join on the same relation path already exists — it may have been
            // added by applyReadRightsQuery(), service methods, or other QueryBuilder logic
            // that ran before expand processing. If so, reuse it to avoid duplicate SQL JOINs.
            // The existing alias may differ from the expand convention — e.g. rights queries
            // use "{ModelAlias}_{property}_rights" while expand would generate "{ModelAlias}_{property}".
            // This is safe because property-hiding uses getPropertyPathRecursively() (explicit
            // path from the expand tree), not the Doctrine alias.
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
                        // Reuse the existing alias (could be "account", "RouteProblem_account_rights", etc.)
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
            // Apply nested select options to the joined entity, if provided.
            // Example: expand=account(select=deviceGeneratedId,nickname) produces a partial
            // select on the joined alias and hides all other Account properties from the response.
            //
            // We pass joinPath explicitly (e.g. "account", "account.world") so that
            // addPropertiesToHideByByJoinPath() uses the correct path regardless of the
            // Doctrine alias. This matters when the join was pre-created by other code
            // (applyReadRightsQuery(), service methods, custom QueryBuilder logic) with an
            // arbitrary alias (e.g. "RouteProblem_account_rights") that the expand reuses —
            // without the explicit path, the alias would be parsed and could map to the
            // wrong join path.
            if (isset($expandOption->selectOptions)) {
                $expandOption->selectOptions->applySelectToDoctrineQueryBuilder(
                    queryBuilder: $queryBuilder,
                    baseModelClass: $targetPropertyModelClass,
                    baseModelAlias: $expandOption->joinAlias,
                    joinPath: $expandOption->getPropertyPathRecursively(),
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
            // When the expand targets a collection property (e.g. `?Messages $messages`),
            // ExpandDefinition::getTargetPropertyRepoClass() resolves to the DBEntitySet
            // class — but applyReadRightsQuery() / createQueryBuilder() / getBaseModelAlias()
            // are defined per-entity, not per-set. DBEntitySet inherits a no-op
            // applyReadRightsQuery() default from DatabaseRepoEntity, so without this
            // deref any user-defined rights logic on the DBEntity is silently bypassed.
            // The set's BASE_REPO_CLASS points at the corresponding DBEntity.
            if ($targetPropertyRepoClass && is_subclass_of($targetPropertyRepoClass, DBEntitySet::class)) {
                $targetPropertyRepoClass = $targetPropertyRepoClass::BASE_REPO_CLASS;
            }
            if ($targetPropertyRepoClass && method_exists($targetPropertyRepoClass, 'applyReadRightsQuery')) {
                /** @var DBEntity $baseRepoClass */
                // Build the rights QueryBuilder *with* SELECT/FROM so that the documented
                // leftJoin-pattern in applyReadRightsQuery() can resolve its root alias
                // (Doctrine's leftJoin → findRootAlias → getRootAlias() throws "No alias
                // was set before invoking getRootAlias()" on an empty QueryBuilder).
                // The temp QB's FROM + internal LEFT JOINs + WHERE are reassembled into
                // an IN-subquery and attached to the expand's LEFT JOIN ON-clause —
                // see applyConditionsFromReadRightsQueryBuilder().
                $rightsQueryBuilder = $targetPropertyRepoClass::createQueryBuilder(true);
                $null = null;
                $readRightsFiltersApplied = $targetPropertyRepoClass::applyReadRightsQuery($rightsQueryBuilder);
                $targetPropertyModelAlias = $targetPropertyRepoClass::getBaseModelAlias();

                if ($readRightsFiltersApplied) {
                    // Both to-one and to-many expand targets push the joined entity's
                    // rights into the LEFT JOIN's ON-clause as an IN-subquery. Children
                    // that fail rights are simply not joined; the parent row always
                    // survives. This matches LazyLoad semantics ($parent->relation is
                    // null when the related entity fails rights — the parent stays)
                    // and avoids the asymmetric parent-collapse the original to-one
                    // WHERE-wrap caused. If a parent's visibility legitimately depends
                    // on a child's rights, the parent's own applyReadRightsQuery must
                    // model that dependency explicitly (e.g. via a leftJoin filter on
                    // the child's columns) — implicit child→parent cascade is wrong
                    // because it conflates "I can't read X" with "X doesn't exist".
                    $this->applyConditionsFromReadRightsQueryBuilder(
                        $queryBuilder,
                        $rightsQueryBuilder,
                        $targetPropertyModelAlias,
                        $expandOption->joinAlias
                    );
                }
            }
        }
        return $queryBuilder;
    }

    /**
     * Pushes the joined entity's rights into the expand's LEFT JOIN ON-clause as an
     * `IN`-subquery. If the joined row fails rights, it is simply not joined — the
     * alias is NULL for that parent and the parent row survives. Matches LazyLoad
     * semantics ($parent->relation returns null when the related entity fails rights,
     * the parent is not affected) and the to-many expand path.
     *
     * Historical note (2026-05): this method previously emitted a WHERE-wrap of the
     * form "(targetJoinAlias.id IS NULL OR (<rights>))", which collapsed the parent
     * row whenever a FK-set to-one target failed rights. That was asymmetric vs both
     * LazyLoad and to-many expand. The new behaviour is unified with
     * {@see self::applyToManyConditionsFromReadRightsQueryBuilder()} — both delegate
     * to the same ON-clause IN-subquery construction so to-one and to-many failures
     * have identical "null the child, keep the parent" semantics. Callers that
     * legitimately want the old "drop parent if child unreadable" behaviour must
     * encode that dependency explicitly in the parent's own applyReadRightsQuery
     * (e.g. via a leftJoin + WHERE on the child's columns); implicit cascade in the
     * other direction is not supported by design.
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
        $this->applyToManyConditionsFromReadRightsQueryBuilder(
            $mainQueryBuilder,
            $rightsQueryBuilder,
            $tempBaseAlias,
            $targetJoinAlias
        );
    }

    /**
     * Shared rights-projection used by both to-one and to-many expand targets.
     * {@see self::applyConditionsFromReadRightsQueryBuilder()} delegates here.
     *
     * Pushes the joined entity's rights into the expand's LEFT JOIN ON-clause as
     * an `IN`-subquery: children that fail rights are simply not joined. The parent
     * row is preserved with a NULL'd join alias — matching the lazy-load contract
     * (`$parent->relation` returns null on rights failure; the parent is untouched).
     *
     * DQL allows IN-subqueries with their own FROM + LEFT JOINs + WHERE inside
     * WITH-clauses, so the rights query (including any internal leftJoins it
     * created) is embedded self-contained in the subquery body — no alias
     * rewriting on the main query.
     *
     * @param DoctrineQueryBuilder $mainQueryBuilder
     * @param DoctrineQueryBuilder $rightsQueryBuilder
     * @param string $tempBaseAlias the rights QB's table alias (from getBaseModelAlias)
     * @param string $targetJoinAlias the expand's LEFT JOIN alias on the main query
     */
    public function applyToManyConditionsFromReadRightsQueryBuilder(
        DoctrineQueryBuilder $mainQueryBuilder,
        DoctrineQueryBuilder $rightsQueryBuilder,
        string $tempBaseAlias,
        string $targetJoinAlias
    ): void {
        $wherePart = $rightsQueryBuilder->getDQLPart('where');
        if (!$wherePart) {
            // No WHERE on the rights QB means no filter to push — rights-internal
            // leftJoins alone don't restrict anything visible to the caller.
            return;
        }

        $fromParts = $rightsQueryBuilder->getDQLPart('from');
        if (empty($fromParts)) {
            return;
        }
        /** @var \Doctrine\ORM\Query\Expr\From $from */
        $from = $fromParts[0];

        // Reindex named parameters from the rights QB onto the main QB as positional
        // placeholders. The subquery references the same placeholders in its own scope —
        // Doctrine compiles DQL placeholders into the outer SQL parameter list either way,
        // so binding to $mainQueryBuilder is correct.
        $whereString = (string)$wherePart;
        $whereString = $this->reindexPlaceholdersAndApplyParameters(
            $whereString,
            $mainQueryBuilder,
            $rightsQueryBuilder->getParameters()
        );

        // Carry over the rights' internal LEFT JOINs into the subquery body verbatim.
        // No alias swapping needed — the subquery scope is independent from the main
        // query, and Doctrine resolves DQL aliases per (sub)select.
        $joinClauses = [];
        $allJoins = $rightsQueryBuilder->getDQLPart('join')[$tempBaseAlias] ?? [];
        foreach ($allJoins as $join) {
            /** @var Join $join */
            $clause = sprintf('LEFT JOIN %s %s', $join->getJoin(), $join->getAlias());
            $joinCondition = $join->getCondition();
            if ($joinCondition) {
                $joinCondition = $this->reindexPlaceholdersAndApplyParameters(
                    (string)$joinCondition,
                    $mainQueryBuilder,
                    $rightsQueryBuilder->getParameters()
                );
                $clause .= ' ' . ($join->getConditionType() ?: Join::WITH) . ' ' . $joinCondition;
            }
            $joinClauses[] = $clause;
        }

        $subqueryDQL = sprintf(
            'SELECT %s.id FROM %s %s%s WHERE %s',
            $tempBaseAlias,
            $from->getFrom(),
            $tempBaseAlias,
            $joinClauses ? ' ' . implode(' ', $joinClauses) : '',
            $whereString
        );

        $this->appendToLeftJoinOnCondition(
            $mainQueryBuilder,
            $targetJoinAlias,
            sprintf('%s.id IN (%s)', $targetJoinAlias, $subqueryDQL)
        );
    }

    /**
     * Appends a condition (AND-combined) to the ON/WITH-clause of an existing LEFT JOIN
     * identified by its alias. Doctrine Join objects are immutable on the condition field,
     * so the join is reconstructed via reset+re-add — same pattern
     * FiltersOptions::applyFiltersToJoin uses.
     */
    protected function appendToLeftJoinOnCondition(
        DoctrineQueryBuilder $queryBuilder,
        string $joinAlias,
        string $additionalCondition
    ): bool {
        $allJoins = $queryBuilder->getDQLPart('join');
        $modified = false;
        foreach ($allJoins as $rootAlias => $joins) {
            foreach ($joins as $index => $join) {
                if (!($join instanceof Join) || $join->getAlias() !== $joinAlias) {
                    continue;
                }
                $existingCondition = $join->getCondition();
                if ($existingCondition === null
                    || (is_string($existingCondition) && trim($existingCondition) === '')) {
                    $combinedCondition = $additionalCondition;
                } else {
                    $combinedCondition = sprintf(
                        '(%s) AND (%s)',
                        (string)$existingCondition,
                        $additionalCondition
                    );
                }
                $allJoins[$rootAlias][$index] = new Join(
                    $join->getJoinType(),
                    $join->getJoin(),
                    $join->getAlias(),
                    $join->getConditionType() ?: Join::WITH,
                    $combinedCondition,
                    $join->getIndexBy()
                );
                $modified = true;
            }
        }
        if (!$modified) {
            return false;
        }
        $queryBuilder->resetDQLPart('join');
        foreach ($allJoins as $rootAlias => $joins) {
            foreach ($joins as $join) {
                $queryBuilder->add('join', [$rootAlias => $join], true);
            }
        }
        return true;
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