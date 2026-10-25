<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

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
 * tree walker appends `<root>.<id> ASC` whenever an entity-hydrating `SELECT` does not already end its `ORDER BY`
 * on the root identifier (including when there is no `ORDER BY` at all).
 *
 * Registered as a **default custom tree walker** (`Query::HINT_CUSTOM_TREE_WALKERS`) by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()} — MariaDB never runs it (D13: "MariaDB:
 * opt-in later, own golden" — registering it there would be a deliberate, separately-golden-tested behaviour
 * change, not this step's scope).
 *
 * **Skipped** (left exactly as the query already is) when:
 * - the query is not a single-root entity hydration (`GROUP BY`, `HAVING`, `DISTINCT`, or a `SELECT` expression
 *   that is an {@see AST\AggregateExpression} — `COUNT`/`SUM`/`AVG`/`MIN`/`MAX` directly in the select list);
 * - the `FROM` clause has more than one root identification variable (D13 is scoped to the single-root shape
 *   pagination and set-load rely on; a multi-root query is rare and not exercised by any fixture);
 * - the root entity's identifier is not a single scalar field (no composite-key support here);
 * - the `ORDER BY` already ends on the root identifier;
 * - the `ORDER BY`'s last item is a vector-distance expression ({@see PostgresCosineDistance},
 *   {@see PostgresEuclideanDistance}, {@see PostgresVecDistance}, or the MariaDB equivalents under
 *   `DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query`) — appending anything after a KNN `ORDER BY` would stop the
 *   ANN index from serving it (D13: "never after a vector-distance item").
 *
 * **Not yet covered** (left for a follow-up, not required by this step's gate): the to-many expand child
 * tie-breaker (`<child>.id` / `<child>.displayOrder`) D13 also describes — only the root tie-breaker is added here.
 */
final class DefaultOrderTreeWalker extends TreeWalkerAdapter
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

        $rootAlias = $fromDeclarations[0]->rangeVariableDeclaration->aliasIdentificationVariable;
        $queryComponents = $this->getQueryComponents();
        $rootMetadata = $queryComponents[$rootAlias]['metadata'] ?? null;
        if ($rootMetadata === null || count($rootMetadata->identifier) !== 1) {
            return;
        }
        $idField = $rootMetadata->identifier[0];

        if ($AST->orderByClause !== null) {
            if ($this->endsOnRootIdentifier($AST->orderByClause, $rootAlias, $idField)) {
                return;
            }
            if ($this->endsOnVectorDistance($AST->orderByClause)) {
                return;
            }
        }

        $idPathExpression = new AST\PathExpression(AST\PathExpression::TYPE_STATE_FIELD, $rootAlias, $idField);
        // A normally-PARSED PathExpression gets its ->type set by Parser::processDeferredPathExpressions() after
        // the grammar pass; a manually-constructed one (this one) never goes through that, so ->type stays null
        // and SqlWalker::walkPathExpression()'s switch on ->type falls to its `default: throw
        // QueryException::invalidPathExpression()` branch. ->expectedType (constructor arg 1) is a DIFFERENT
        // property (the grammar's expectation, checked by the parser) and does not substitute for it.
        $idPathExpression->type = AST\PathExpression::TYPE_STATE_FIELD;
        $idOrderByItem = new AST\OrderByItem($idPathExpression);
        $idOrderByItem->type = 'ASC';

        if ($AST->orderByClause === null) {
            $AST->orderByClause = new AST\OrderByClause([$idOrderByItem]);
        } else {
            $AST->orderByClause->orderByItems[] = $idOrderByItem;
        }
    }

    protected function selectsAnAggregate(AST\SelectStatement $AST): bool
    {
        foreach ($AST->selectClause->selectExpressions as $selectExpression) {
            if ($selectExpression->expression instanceof AST\AggregateExpression) {
                return true;
            }
            foreach (self::AGGREGATE_FUNCTION_CLASSES as $aggregateFunctionClass) {
                if ($selectExpression->expression instanceof $aggregateFunctionClass) {
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
