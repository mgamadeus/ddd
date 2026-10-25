<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Utility\LockSqlHelper;
use RuntimeException;

/**
 * §4.8 (dual-dialect architecture plan): the default output walker for every non-paginated PostgreSQL query —
 * registered via `Query::HINT_CUSTOM_OUTPUT_WALKER` by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}. Two responsibilities beyond the stock
 * {@see SqlWalker}:
 *
 * 1. **NULL ordering** ({@see NullsOrderingTrait}): `walkOrderByItem()` appends `NULLS FIRST`/`NULLS LAST` so a
 *    nullable `ORDER BY` matches MariaDB's NULL-as-smallest ordering.
 * 2. **Row locking without the paginator.** `walkSelectStatement()` never calls `parent::walkSelectStatement()` —
 *    that method finalises through {@see \Doctrine\ORM\Query\Exec\SingleSelectSqlFinalizer}, whose lock clause is
 *    a bare `FOR UPDATE`/`FOR SHARE` that PostgreSQL REJECTS the moment the query has an outer join ("FOR UPDATE
 *    cannot be applied to the nullable side of an outer join"). This walker builds the same
 *    `createSqlForFinalizer()` SQL, applies `modifyLimitQuery()` itself, then appends `FOR UPDATE OF <root alias>`
 *    when the query has any join (restricting the lock to the root table only) or a bare `FOR UPDATE` when it has
 *    none; `PESSIMISTIC_READ` renders `FOR SHARE` unconditionally (`FOR SHARE OF` has the same outer-join
 *    restriction, but no fixture here exercises a locked read with joins — a future one would need the same `OF`
 *    treatment). Row locking is never combined with {@see PostgresLimitSubqueryOutputWalker} — a locked, paginated,
 *    joined query is out of this step's scope (architecture plan §4.8: "Row locking is never combined with the
 *    pagination walker").
 */
class PostgresSqlWalker extends SqlWalker
{
    use NullsOrderingTrait;
    use LockSqlHelper;
    use JsonColumnAwareTrait;
    use PostgresComparisonTrait;

    /** {@inheritDoc} */
    public function walkOrderByItem($orderByItem)
    {
        $expression = $orderByItem->expression;
        $type = strtoupper($orderByItem->type);

        if ($this->isJsonColumnPathExpression($expression)) {
            $sql = $this->castJsonColumnSqlForOrdering($expression->dispatch($this)) . ' ' . $type;
        } else {
            $sql = parent::walkOrderByItem($orderByItem);
        }

        if ($expression instanceof AST\Subselect) {
            return $sql;
        }

        return $this->appendNullsOrdering($sql, $type, $expression);
    }

    /** {@inheritDoc} */
    public function walkLikeExpression($likeExpr)
    {
        $stringExpr = $likeExpr->stringExpression;
        if (!$this->isJsonColumnPathExpression($stringExpr)) {
            return parent::walkLikeExpression($likeExpr);
        }

        $sql = $this->castJsonColumnSqlForComparison($stringExpr->dispatch($this)) . ($likeExpr->not ? ' NOT' : '') . ' LIKE ';

        if ($likeExpr->stringPattern instanceof AST\InputParameter) {
            $sql .= $this->walkInputParameter($likeExpr->stringPattern);
        } elseif ($likeExpr->stringPattern instanceof AST\Functions\FunctionNode) {
            $sql .= $this->walkFunction($likeExpr->stringPattern);
        } elseif ($likeExpr->stringPattern instanceof AST\PathExpression) {
            $sql .= $this->walkPathExpression($likeExpr->stringPattern);
        } else {
            $sql .= $this->walkLiteral($likeExpr->stringPattern);
        }

        if ($likeExpr->escapeChar) {
            $sql .= ' ESCAPE ' . $this->walkLiteral($likeExpr->escapeChar);
        }

        return $sql;
    }

    /** {@inheritDoc} */
    public function walkInListExpression(AST\InListExpression $inExpr): string
    {
        $expression = $inExpr->expression;
        $expressionSql = $this->isJsonColumnExpression($expression)
            ? $this->castJsonColumnSqlForComparison($expression->dispatch($this))
            : $this->walkArithmeticExpression($expression);

        return $expressionSql
            . ($inExpr->not ? ' NOT' : '') . ' IN ('
            . implode(', ', array_map([$this, 'walkInParameter'], $inExpr->literals))
            . ')';
    }

    /** {@inheritDoc} */
    public function walkBetweenExpression($betweenExpr)
    {
        $expression = $betweenExpr->expression;
        if (!$this->isJsonColumnExpression($expression)) {
            return parent::walkBetweenExpression($betweenExpr);
        }

        $sql = $this->castJsonColumnSqlForComparison($expression->dispatch($this)) . ($betweenExpr->not ? ' NOT' : '');
        $sql .= ' BETWEEN ' . $this->walkArithmeticExpression($betweenExpr->leftBetweenExpression)
            . ' AND ' . $this->walkArithmeticExpression($betweenExpr->rightBetweenExpression);

        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * D8 (`GROUP BY`): only the direct state-field `PathExpression` item (the common case, and the only shape a
     * `json` column reaches on its own) is handled here; the string-keyed branches (a result-variable alias, or
     * `GROUP BY <wholeEntityAlias>` expanding to one `PathExpression` per field) delegate to `parent::` unchanged
     * — that expansion over an entity with `json` columns is not covered (no fixture here exercises it; see
     * {@see self::castJsonSelectColumnsForDistinctOrGroupBy()}'s own deviation note).
     */
    public function walkGroupByItem($groupByItem)
    {
        if ($groupByItem instanceof AST\PathExpression && $this->isJsonColumnPathExpression($groupByItem)) {
            return $this->castJsonColumnSqlForComparison($this->walkPathExpression($groupByItem));
        }

        return parent::walkGroupByItem($groupByItem);
    }

    /** {@inheritDoc} */
    public function walkSelectStatement(AST\SelectStatement $AST)
    {
        $platform = $this->getConnection()->getDatabasePlatform();
        $query = $this->getQuery();

        $sql = $this->createSqlForFinalizer($AST);
        if ($AST->selectClause->isDistinct || $AST->groupByClause !== null) {
            $sql = $this->castJsonSelectColumnsForDistinctOrGroupBy($AST->selectClause, $sql);
        }
        $sql = $platform->modifyLimitQuery($sql, $query->getMaxResults(), $query->getFirstResult());

        $lockMode = $query->getHint(Query::HINT_LOCK_MODE) ?: LockMode::NONE;
        if (
            $lockMode !== LockMode::NONE
            && $lockMode !== LockMode::OPTIMISTIC
            && $lockMode !== LockMode::PESSIMISTIC_READ
            && $lockMode !== LockMode::PESSIMISTIC_WRITE
        ) {
            throw QueryException::invalidLockMode();
        }

        if ($lockMode === LockMode::PESSIMISTIC_READ) {
            $sql .= ' ' . $this->getReadLockSQL($platform);
        } elseif ($lockMode === LockMode::PESSIMISTIC_WRITE) {
            $sql .= ' ' . $this->writeLockClause($AST, $platform);
        }

        return $sql;
    }

    /** `FOR UPDATE OF <root>` when the query has any join, plain `FOR UPDATE` otherwise. */
    protected function writeLockClause(AST\SelectStatement $AST, $platform): string
    {
        $fromDeclarations = $AST->fromClause->identificationVariableDeclarations;
        if (count($fromDeclarations) !== 1) {
            return $this->getWriteLockSQL($platform);
        }

        $rootDeclaration = $fromDeclarations[0];
        if (count($rootDeclaration->joins) === 0) {
            return $this->getWriteLockSQL($platform);
        }

        $rangeVariableDeclaration = $rootDeclaration->rangeVariableDeclaration;
        if (!$rangeVariableDeclaration instanceof AST\RangeVariableDeclaration) {
            return $this->getWriteLockSQL($platform);
        }

        $rootAlias = $rangeVariableDeclaration->aliasIdentificationVariable;
        $rootClass = $this->getMetadataForDqlAlias($rootAlias);
        $rootTableAlias = $this->getSQLTableAlias($rootClass->getTableName(), $rootAlias);

        return 'FOR UPDATE OF ' . $rootTableAlias;
    }

    /**
     * D8 (case (c), dual-dialect architecture plan §4.8): `SELECT DISTINCT` and `GROUP BY` both need a hash/
     * equality operator on EVERY selected column — `json` has none (real failure: "could not identify an equality
     * operator for type json", `SELECT DISTINCT` hydrating `OrderItem`, whose `unitPrice`/`optionsPriceDelta` are
     * JSON-persisted `MoneyAmount` value objects — {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder::distinct()}'s
     * to-many-LEFT-JOIN dedup).
     *
     * {@see \Doctrine\ORM\Query\SqlWalker::walkSelectExpression()} cannot be overridden to cast the json columns it
     * projects — it reads `$rsm`/`$quoteStrategy`/`$em`/… , all declared `private` on `SqlWalker` itself, invisible
     * from this subclass. Instead, this method edits the ALREADY-RENDERED SQL text — the same technique
     * {@see MariaDbDistinctSubqueryPagination} already uses for its own dialect-specific SQL surgery: for every
     * json-typed column this select clause projects, it recomputes the EXACT substring
     * `walkSelectExpression()` would itself have produced for that column (same table alias, same quoted column
     * name — both deterministic and memoized, so calling the inherited, un-overridden `walkPathExpression()` again
     * here returns byte-identical text, no new alias, no side effect) and swaps it for its text-cast form only
     * where it is immediately followed by ` AS ` — which only ever happens in the select list this method just
     * rendered (a `WHERE`/`ORDER BY`/`GROUP BY` reference to the same column carries no ` AS alias` suffix, so it
     * is never touched here). The VALUE is unaffected: PostgreSQL's wire format for a selected `json` column is
     * already its text form, so casting it to `text` changes only the SQL, never the hydrated value.
     *
     * Deviation: `GROUP BY <wholeEntityAlias>` (Doctrine's own per-field `PathExpression` expansion inside
     * {@see \Doctrine\ORM\Query\SqlWalker::walkGroupByItem()}) is not covered — no fixture here exercises it.
     */
    protected function castJsonSelectColumnsForDistinctOrGroupBy(AST\SelectClause $selectClause, string $sql): string
    {
        foreach ($this->collectJsonSelectPathExpressions($selectClause) as $pathExpression) {
            $columnSql = $this->walkPathExpression($pathExpression);
            $sql = str_replace(
                $columnSql . ' AS ',
                $this->castJsonColumnSqlForComparison($columnSql) . ' AS ',
                $sql
            );
        }

        return $sql;
    }

    /**
     * One synthetic, already-typed `TYPE_STATE_FIELD` {@see AST\PathExpression} per json-typed column this select
     * clause projects — covering a bare state-field select item (`SELECT DISTINCT oi.name FROM ...`) and the
     * whole/partial-entity hydration item (`SELECT DISTINCT oi FROM ...` / `SELECT DISTINCT PARTIAL oi.{...}`),
     * the same two shapes {@see \Doctrine\ORM\Query\SqlWalker::walkSelectExpression()} itself distinguishes.
     *
     * @return list<AST\PathExpression>
     */
    protected function collectJsonSelectPathExpressions(AST\SelectClause $selectClause): array
    {
        $pathExpressions = [];

        foreach ($selectClause->selectExpressions as $selectExpression) {
            $expression = $selectExpression->expression;

            if ($expression instanceof AST\PathExpression) {
                if ($this->isJsonColumnPathExpression($expression)) {
                    $pathExpressions[] = $this->newStateFieldPathExpression($expression->identificationVariable, $expression->field);
                }
                continue;
            }

            if (is_string($expression)) {
                $dqlAlias = $expression;
                $partialFieldSet = [];
            } elseif ($expression instanceof AST\PartialObjectExpression) {
                $dqlAlias = $expression->identificationVariable;
                $partialFieldSet = $expression->partialFieldSet;
            } else {
                continue;
            }

            $class = $this->getMetadataForDqlAlias($dqlAlias);
            foreach ($class->fieldMappings as $fieldName => $mapping) {
                if ($partialFieldSet && !in_array($fieldName, $partialFieldSet, true)) {
                    continue;
                }
                if (($mapping['type'] ?? null) === 'json') {
                    $pathExpressions[] = $this->newStateFieldPathExpression($dqlAlias, $fieldName);
                }
            }
        }

        return $pathExpressions;
    }
}
