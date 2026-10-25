<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Tools\Pagination\LimitSubqueryOutputWalker;

/**
 * D2/§4.8 (dual-dialect architecture plan): the id-page half of {@see RowNumberPagePagination}. Extends Doctrine's
 * stock {@see LimitSubqueryOutputWalker} — which already detects `PostgreSQLPlatform` and takes its ROW_NUMBER
 * branch (`platformSupportsRowNumber()`/`createSqlWithRowNumber()`). The ONLY thing this subclass adds is NULL
 * ordering ({@see NullsOrderingTrait}): the inner `ROW_NUMBER() OVER (ORDER BY …)` must sort NULLs the same way
 * MariaDB does, or a page boundary falls in a different place than the full (non-paginated) result's `ORDER BY`
 * would put it. `walkOrderByItem()` is not overridden by the parent class, so overriding it here reaches every
 * `ORDER BY` item the ROW_NUMBER clause walks ({@see \Doctrine\ORM\Tools\Pagination\RowNumberOverFunction::getSql()}
 * calls `$sqlWalker->walkOrderByClause()` on THIS instance). D8 (dual-dialect architecture plan §4.8,
 * {@see JsonColumnAwareTrait}): the same clause can order by a `json`-typed column (a raw `orderBy()` on a
 * Translatable, reaching this walker whenever that query also has joins + a limit/offset —
 * {@see RowNumberPagePagination} / `DoctrineQueryBuilder::applyDistinctSubqueryIfNeededAndGetResult()`), so
 * `walkOrderByItem()` casts it to text here too, the same way {@see PostgresSqlWalker} does for every
 * non-paginated query.
 *
 * **How {@see RowNumberPagePagination} drives this class.** The stock class's `walkSelectStatementWithRowNumber()`
 * is `public` precisely so a caller outside the stock `Paginator` can get the id-page SQL directly with the
 * real `LIMIT`/`OFFSET` applied — but reaching it needs a `($query, $parserResult, $queryComponents)` triple this
 * package has no reason to hand-build, since {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineParser} ("always
 * takes the executor path") never calls it either: `walkSelectStatement()` (what DOES run, via the ordinary
 * `Query::getSQL()` pipeline) builds its `SingleSelectSqlFinalizer` executor against `$this->getQuery()` — the
 * walker's OWN internally-cloned, limit-RESET query (`LimitSubqueryOutputWalker::__construct()`) — so the SQL text
 * `getSQL()` returns is the id-page query WITHOUT `LIMIT`/`OFFSET`. `RowNumberPagePagination` calls the ordinary
 * `$idQuery->getSQL()` for that text, then applies the real `$platform->modifyLimitQuery()` itself — the exact
 * same call `walkSelectStatementWithRowNumber()` would have made, just from outside instead of reaching in.
 */
class PostgresLimitSubqueryOutputWalker extends LimitSubqueryOutputWalker
{
    use NullsOrderingTrait;
    use JsonColumnAwareTrait;

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
}
