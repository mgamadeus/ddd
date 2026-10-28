<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST;

/**
 * D13/§4.8: MariaDB sorts NULLs first for `ASC` (and last for `DESC`, standard NULL-as-smallest total ordering);
 * PostgreSQL's default is the opposite (`NULLS LAST` for `ASC`, `NULLS FIRST` for `DESC`) unless told otherwise.
 * Shared by {@see PostgresSqlWalker} (the default output walker) and {@see PostgresLimitSubqueryOutputWalker}
 * (the pagination id-page walker) so both append an explicit `NULLS FIRST`/`NULLS LAST` clause that reproduces
 * MariaDB's ordering.
 *
 * **Every {@see AST\PathExpression} is treated as potentially nullable** — not computed from Doctrine's own
 * metadata. Verified empirically (2026-10-25, the integration fixture's own `CounterRow.occurredAt`): the
 * generated `DBCounterRowModel`'s `#[ORM\Column]` attribute carries no `nullable: true` even though the real
 * column — and the entity's own `?DateTime` PHP type — demonstrably holds `NULL` rows, so
 * `ClassMetadata::$fieldMappings[$field]['nullable']` reads `false` for it; trusting that metadata produced a
 * silent correctness bug (no `NULLS FIRST` emitted, wrong row order on real data) instead of a loud one. An
 * always-nullable `PathExpression` costs one harmless `NULLS FIRST`/`NULLS LAST` clause on a column that never
 * actually holds `NULL` (a no-op); a false NOT-NULL costs a wrong row order. Any other expression (a function
 * call — vector/spatial distance included, `FULLTEXT_SCORE`, `COALESCE`, …) is still treated as NOT NULL, matching
 * D13's "distance functions count as NOT NULL" (needed so appending a tie-breaker never lands after a KNN
 * `ORDER BY` and defeats its ANN index).
 */
trait NullsOrderingTrait
{
    /**
     * Appends `NULLS FIRST`/`NULLS LAST` (MariaDB's NULL-as-smallest ordering) to an already-rendered
     * `"<sql> ASC"` / `"<sql> DESC"` order-by-item string, when `$expression` can be null.
     */
    protected function appendNullsOrdering(string $renderedOrderByItemSql, string $type, mixed $expression): string
    {
        if (!$this->isNullableOrderByExpression($expression)) {
            return $renderedOrderByItemSql;
        }

        return $renderedOrderByItemSql . ($type === 'ASC' ? ' NULLS FIRST' : ' NULLS LAST');
    }

    protected function isNullableOrderByExpression(mixed $expression): bool
    {
        // JSON_TEXT(col): the text of a JSON / Translatable column the order-by options sort on — as nullable as
        // the column (without it, rows without a value sorted last on PostgreSQL and first on MariaDB)
        return $expression instanceof AST\PathExpression || $expression instanceof JsonText;
    }
}
