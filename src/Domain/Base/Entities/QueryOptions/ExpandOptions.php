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
                // applyConditionsFromReadRightsQueryBuilder() only harvests WHERE and JOIN
                // parts keyed by the temp base alias and rewrites the alias to the target
                // expand join alias on the main query — the FROM/SELECT we add here are
                // local to the temp QB and never bleed into the main query.
                $rightsQueryBuilder = $targetPropertyRepoClass::createQueryBuilder(true);
                $null = null;
                $readRightsFiltersApplied = $targetPropertyRepoClass::applyReadRightsQuery($rightsQueryBuilder);
                $targetPropertyModelAlias = $targetPropertyRepoClass::getBaseModelAlias();

                if ($readRightsFiltersApplied) {
                    // Branch on the expand's target cardinality. For to-one targets the
                    // WHERE-with-NULL-safety wrap is semantically correct (a parent with an
                    // unreadable to-one is invalid → should not appear). For to-many targets
                    // the WHERE-wrap collapses the parent when every joined child fails
                    // rights — Doctrine's DISTINCT-on-parent.id subquery returns no parent
                    // → 404 even though the parent should be visible with a (possibly empty)
                    // filtered child collection. The to-many path pushes rights into the
                    // LEFT JOIN's ON-clause via an IN-subquery instead, so children that
                    // fail rights are simply not joined.
                    $targetPropertyClass = $expandOption->expandDefinition?->getTargetPropertyClass();
                    $isToManyExpand = $targetPropertyClass
                        && is_a($targetPropertyClass, ObjectSet::class, true);
                    if ($isToManyExpand) {
                        $this->applyToManyConditionsFromReadRightsQueryBuilder(
                            $queryBuilder,
                            $rightsQueryBuilder,
                            $targetPropertyModelAlias,
                            $expandOption->joinAlias
                        );
                    } else {
                        $this->applyConditionsFromReadRightsQueryBuilder(
                            $queryBuilder,
                            $rightsQueryBuilder,
                            $targetPropertyModelAlias,
                            $expandOption->joinAlias
                        );
                    }
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
            // Apply the modified WHERE to the main query, but null-safe wrt the expand
            // join: if the optional LEFT JOIN to $targetJoinAlias produced no row
            // (e.g. SupportMessage.sentFromSupportEmailAddress is NULL on app-channel
            // messages), the rights condition references all-NULL aliases and would
            // otherwise filter out the parent row. Wrapping with "$targetJoinAlias.id
            // IS NULL OR (<rights>)" preserves the parent row when the expand is
            // simply absent, while still enforcing rights when the expand is present.
            // For non-nullable expands the IS-NULL branch is never true, so behavior
            // is unchanged.
            //
            // This method handles to-one expand targets only. For to-many targets the
            // WHERE-wrap is incorrect (it can collapse the parent when all joined
            // children fail rights); the to-many path is in
            // applyToManyConditionsFromReadRightsQueryBuilder() which pushes rights
            // into the LEFT JOIN's ON-clause as an IN-subquery instead.
            $mainQueryBuilder->andWhere(sprintf('(%s.id IS NULL OR (%s))', $targetJoinAlias, $whereString));
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

    /**
     * Variant of {@see self::applyConditionsFromReadRightsQueryBuilder()} for to-many expand
     * targets (collection-typed properties like `?Messages $messages`).
     *
     * The classic WHERE-with-NULL-safety wrap collapses for to-many: a parent with N joined
     * children where every child fails rights would lose every combined row to the WHERE
     * filter, and the standard DISTINCT-on-parent.id subquery Doctrine builds for eager-find
     * would return no parent — even though the caller-intent is "filter the collection, keep
     * the parent" (matching the lazy-load semantic of `$parent->collection`).
     *
     * To preserve the parent we push rights into the LEFT JOIN's ON-clause as an IN-subquery:
     * children that fail rights are not joined at all. DQL allows IN-subqueries (with their
     * own FROM + JOINs + WHERE) inside WITH-clauses, so the rights query — including any
     * internal leftJoins it created — is embedded self-contained in the subquery body.
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