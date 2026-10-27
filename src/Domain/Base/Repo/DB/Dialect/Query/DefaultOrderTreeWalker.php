<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\AST\Functions\AvgFunction;
use Doctrine\ORM\Query\AST\Functions\CountFunction;
use Doctrine\ORM\Query\AST\Functions\MaxFunction;
use Doctrine\ORM\Query\AST\Functions\MinFunction;
use Doctrine\ORM\Query\AST\Functions\SumFunction;
use Doctrine\ORM\Query\TreeWalkerAdapter;

/**
 * D13 (dual-dialect architecture plan §3): InnoDB returns rows in primary-key order with no `ORDER BY`; a
 * PostgreSQL heap has no such guarantee. Code that relies on it — set-load total counts, `ExpandOptions`'s
 * to-many ordering (`ExpandOptions.php:383-385`) — needs a real, deterministic tie-breaker on PostgreSQL. This
 * tree walker:
 *
 * 1. appends `<root>.<id> ASC` whenever an entity-hydrating `SELECT` does not already end its `ORDER BY` on the
 *    root identifier (including when there is no `ORDER BY` at all);
 * 2. then, after that root tie-breaker (and after any pre-existing `ORDER BY` items), appends `<childAlias>.<id>
 *    ASC` for every to-many association join the query has — root → child → grandchild, in the order the joins
 *    appear (DQL flattens nested joins onto the single root's `IdentificationVariableDeclaration`, so one
 *    left-to-right pass over it already visits them parent-before-child). Real failure this fixes: an `Order`
 *    expanded with `orderItems` and their `orderItemOptionSelections` came back with the children in heap order on
 *    PostgreSQL — different from MariaDB's PK-clustered order — on every fetch-joined to-many collection, at every
 *    nesting depth.
 *
 * Registered as a **default custom tree walker** (`Query::HINT_CUSTOM_TREE_WALKERS`) by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()} — MariaDB never runs it (D13: "MariaDB:
 * opt-in later, own golden" — registering it there would be a deliberate, separately-golden-tested behaviour
 * change, not this step's scope).
 *
 * **Skipped entirely** (left exactly as the query already is) when:
 * - the query is not a single-root entity hydration (`GROUP BY`, `HAVING`, `DISTINCT`, or a `SELECT` expression
 *   that is an {@see AST\AggregateExpression} — `COUNT`/`SUM`/`AVG`/`MIN`/`MAX` directly in the select list).
 *   `DISTINCT` in particular is excluded on purpose, not just by omission: PostgreSQL rejects an `ORDER BY` item
 *   that is not itself in the `SELECT DISTINCT` list ("for SELECT DISTINCT, ORDER BY expressions must appear in
 *   select list", 42P10) and neither the root id nor a to-many child id is necessarily selected — the codebase's
 *   only DQL-level `DISTINCT` + to-many-join + limit shape (join+limit set-loads) never reaches this walker as a
 *   `DISTINCT` AST in the first place: {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\RowNumberPagePagination} and
 *   MariaDB's `MariaDbDistinctSubqueryPagination` both inject `DISTINCT` into already-rendered SQL TEXT, after this
 *   walker (which runs on the DQL AST) has already done its job — so this guard is this walker's own defence
 *   against a future caller that calls `$queryBuilder->distinct()` directly on the DQL, not a case this codebase
 *   exercises today;
 * - the `FROM` clause has more than one root identification variable (D13 is scoped to the single-root shape
 *   pagination and set-load rely on; a multi-root query is rare and not exercised by any fixture);
 * - the root entity's identifier is not a single scalar field (no composite-key support here);
 * - the `ORDER BY`'s last item is a vector-distance expression ({@see PostgresCosineDistance},
 *   {@see PostgresEuclideanDistance}, {@see PostgresVecDistance}, or the MariaDB equivalents under
 *   `DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query`) — appending anything after a KNN `ORDER BY` would stop the
 *   ANN index from serving it (D13: "never after a vector-distance item"). This skips BOTH the root and the
 *   to-many child tie-breakers, since both would land after the vector-distance item.
 *
 * **Root tie-breaker only skipped** (child tie-breakers still run) when the `ORDER BY` already ends on the root
 * identifier (an explicit `orderBy=…,id` already makes root order deterministic; to-many children still need
 * their own tie-breaker regardless).
 *
 * **To-many child tie-breakers only skipped** (root tie-breaker still runs) inside
 * {@see RowNumberPagePagination}'s id-page query (`Query::HINT_CUSTOM_OUTPUT_WALKER` overridden to
 * {@see PostgresLimitSubqueryOutputWalker}, detected via {@see self::isBuildingRowNumberIdPageQuery()}): that
 * query's job is to pick which ROOT rows land on the requested page — ordering its `ROW_NUMBER() OVER (ORDER BY
 * …)` by a to-many child's id would let a child-id tie-break reorder which roots are considered "first" on a page
 * boundary, which belongs to the id-page's own root order only. The page's MAIN query (rendered through the
 * default {@see PostgresSqlWalker}, no output-walker override) still gets the full root + child tie-breaker chain
 * — that query carries every join and is what the children's final order is read from.
 */
class DefaultOrderTreeWalker extends TreeWalkerAdapter
{
    /**
     * `COUNT`/`AVG`/`MIN`/`MAX`/`SUM` parse through DQL's generic function dispatch (`Parser::SelectExpression()`'s
     * `isFunction()` branch, not the `AggregateExpression()` grammar rule reached from `SimpleArithmeticExpression`)
     * into these built-in `FunctionNode` subclasses — e.g. a bare `SELECT COUNT(x.id) FROM X x` (DDD's
     * `DBEntitySet::count()`, hit by every entity's list-endpoint total count) yields a top-level
     * `AST\Functions\CountFunction` `SelectExpression`, never an `AST\AggregateExpression` directly. Checking only
     * `AST\AggregateExpression` therefore misses the single most common aggregate shape in the codebase: the
     * walker would append `ORDER BY <root>.id` to a `SELECT COUNT(...)` with no `GROUP BY`, which PostgreSQL
     * rejects (`column "x0_.id" must appear in the GROUP BY clause or be used in an aggregate function`) — MariaDB
     * tolerates it, which is why this never surfaced before the PostgreSQL run.
     *
     * @var class-string[]
     */
    protected const array AGGREGATE_FUNCTION_CLASSES = [
        CountFunction::class,
        AvgFunction::class,
        MinFunction::class,
        MaxFunction::class,
        SumFunction::class,
        // the framework's own aggregate DQL function (PostgreSQL's string_agg)
        StringAggOrdered::class,
    ];

    /** @var class-string[] FunctionNode classes whose result must stay the LAST ORDER BY item (ANN index use) */
    protected const array VECTOR_DISTANCE_FUNCTION_CLASSES = [
        PostgresCosineDistance::class,
        PostgresEuclideanDistance::class,
        PostgresVecDistance::class,
        \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\CosineDistance::class,
        \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\EuclideanDistance::class,
        \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Distance::class,
    ];

    public function walkSelectStatement(AST\SelectStatement $AST): void
    {
        if ($AST->groupByClause !== null || $AST->havingClause !== null || $AST->selectClause->isDistinct) {
            return;
        }

        if ($this->selectsAnAggregate($AST)) {
            return;
        }

        $fromDeclarations = $AST->fromClause->identificationVariableDeclarations;
        if (count($fromDeclarations) !== 1) {
            return;
        }

        $rootDeclaration = $fromDeclarations[0];
        $rootAlias = $rootDeclaration->rangeVariableDeclaration->aliasIdentificationVariable;
        $queryComponents = $this->getQueryComponents();
        $rootMetadata = $queryComponents[$rootAlias]['metadata'] ?? null;
        if ($rootMetadata === null || count($rootMetadata->identifier) !== 1) {
            return;
        }
        $idField = $rootMetadata->identifier[0];

        $appendRootIdTieBreaker = true;
        if ($AST->orderByClause !== null) {
            if ($this->endsOnVectorDistance($AST->orderByClause)) {
                return;
            }
            if ($this->endsOnRootIdentifier($AST->orderByClause, $rootAlias, $idField)) {
                $appendRootIdTieBreaker = false;
            }
        }

        if ($appendRootIdTieBreaker) {
            $this->appendIdOrderByItem($AST, $rootAlias, $idField);
        }

        // D13 to-many child tie-breaker: skip inside RowNumberPagePagination's id-page query — that query only
        // orders roots, children ordering belongs to the page's main query (see class docblock).
        if ($this->isBuildingRowNumberIdPageQuery()) {
            return;
        }

        foreach ($this->findToManyJoinedAliasesInOrder($rootDeclaration, $queryComponents) as $joinedAliasIdentifier) {
            $this->appendIdOrderByItem($AST, $joinedAliasIdentifier->alias, $joinedAliasIdentifier->identifierFieldName);
        }
    }

    /** Appends `<alias>.<field> ASC`, creating the `ORDER BY` clause if the statement has none yet. */
    protected function appendIdOrderByItem(AST\SelectStatement $AST, string $alias, string $field): void
    {
        $pathExpression = new AST\PathExpression(AST\PathExpression::TYPE_STATE_FIELD, $alias, $field);
        // A normally-PARSED PathExpression gets its ->type set by Parser::processDeferredPathExpressions() after
        // the grammar pass; a manually-constructed one (this one) never goes through that, so ->type stays null
        // and SqlWalker::walkPathExpression()'s switch on ->type falls to its `default: throw
        // QueryException::invalidPathExpression()` branch. ->expectedType (constructor arg 1) is a DIFFERENT
        // property (the grammar's expectation, checked by the parser) and does not substitute for it.
        $pathExpression->type = AST\PathExpression::TYPE_STATE_FIELD;
        $orderByItem = new AST\OrderByItem($pathExpression);
        $orderByItem->type = 'ASC';

        if ($AST->orderByClause === null) {
            $AST->orderByClause = new AST\OrderByClause([$orderByItem]);
        } else {
            $AST->orderByClause->orderByItems[] = $orderByItem;
        }
    }

    /**
     * True while this walker is processing {@see RowNumberPagePagination}'s id-page query — the one place that
     * overrides the default `HINT_CUSTOM_OUTPUT_WALKER` ({@see PostgresSqlWalker}) to
     * {@see PostgresLimitSubqueryOutputWalker}. This walker itself (`HINT_CUSTOM_TREE_WALKERS`) is never
     * overridden there, so it still runs on that query's AST and needs a way to tell the two queries apart.
     */
    protected function isBuildingRowNumberIdPageQuery(): bool
    {
        return $this->_getQuery()->getHint(Query::HINT_CUSTOM_OUTPUT_WALKER) === PostgresLimitSubqueryOutputWalker::class;
    }

    /**
     * Walks the root's flattened join list (DQL has no nested `FROM`, so `root → child → grandchild` joins all sit
     * on the same {@see AST\IdentificationVariableDeclaration}, parent before child, in written/generated order)
     * and returns one alias + identifier field per to-many association join (`ONE_TO_MANY`/`MANY_TO_MANY`) — an
     * explicit `JOIN Entity alias WITH …` (no association behind it, `relation` stays `null` in the query
     * component) and a to-one join are both skipped; a to-many join onto a composite-key entity is skipped too (no
     * composite-key support here, same limit the root tie-breaker already has).
     *
     * @param array<string, array<string, mixed>> $queryComponents
     * @return JoinedAliasIdentifiers In join order, parent before child
     */
    protected function findToManyJoinedAliasesInOrder(AST\IdentificationVariableDeclaration $rootDeclaration, array $queryComponents): JoinedAliasIdentifiers
    {
        $joinedAliasIdentifiers = new JoinedAliasIdentifiers();
        foreach ($rootDeclaration->joins as $join) {
            if (!$join instanceof AST\Join) {
                continue;
            }
            $joinDeclaration = $join->joinAssociationDeclaration;
            if (!$joinDeclaration instanceof AST\JoinAssociationDeclaration) {
                continue;
            }
            $alias = $joinDeclaration->aliasIdentificationVariable;

            $relation = $queryComponents[$alias]['relation'] ?? null;
            if ($relation === null) {
                continue;
            }
            if ($relation['type'] !== ClassMetadata::ONE_TO_MANY && $relation['type'] !== ClassMetadata::MANY_TO_MANY) {
                continue;
            }

            $childMetadata = $queryComponents[$alias]['metadata'] ?? null;
            if ($childMetadata === null || count($childMetadata->identifier) !== 1) {
                continue;
            }

            $joinedAliasIdentifier = new JoinedAliasIdentifier($alias, $childMetadata->identifier[0]);
            $joinedAliasIdentifiers->add($joinedAliasIdentifier);
        }

        return $joinedAliasIdentifiers;
    }

    protected function selectsAnAggregate(AST\SelectStatement $AST): bool
    {
        foreach ($AST->selectClause->selectExpressions as $selectExpression) {
            if ($this->containsAnAggregate($selectExpression->expression)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an aggregate occurs anywhere in a select expression — also nested, e.g. `COUNT(DISTINCT x) + 1` (an
     * arithmetic expression around the aggregate) or `COALESCE(SUM(x), 0)`. A subselect is not searched: its
     * aggregates are its own and leave the outer query row-wise.
     */
    protected function containsAnAggregate(mixed $expressionNode): bool
    {
        if ($expressionNode instanceof AST\AggregateExpression) {
            return true;
        }
        foreach (self::AGGREGATE_FUNCTION_CLASSES as $aggregateFunctionClass) {
            if ($expressionNode instanceof $aggregateFunctionClass) {
                return true;
            }
        }
        if ($expressionNode instanceof AST\Subselect) {
            return false;
        }
        if (is_array($expressionNode)) {
            foreach ($expressionNode as $childNode) {
                if ($this->containsAnAggregate($childNode)) {
                    return true;
                }
            }
            return false;
        }
        if ($expressionNode instanceof AST\Node) {
            foreach (get_object_vars($expressionNode) as $childNode) {
                if ((is_array($childNode) || $childNode instanceof AST\Node) && $this->containsAnAggregate($childNode)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function endsOnRootIdentifier(AST\OrderByClause $orderByClause, string $rootAlias, string $idField): bool
    {
        $items = $orderByClause->orderByItems;
        if ($items === []) {
            return false;
        }
        $lastExpression = $items[array_key_last($items)]->expression;

        return $lastExpression instanceof AST\PathExpression
            && $lastExpression->identificationVariable === $rootAlias
            && $lastExpression->field === $idField;
    }

    protected function endsOnVectorDistance(AST\OrderByClause $orderByClause): bool
    {
        $items = $orderByClause->orderByItems;
        if ($items === []) {
            return false;
        }
        $lastExpression = $items[array_key_last($items)]->expression;

        foreach (self::VECTOR_DISTANCE_FUNCTION_CLASSES as $functionClass) {
            if ($lastExpression instanceof $functionClass) {
                return true;
            }
        }
        return false;
    }
}
