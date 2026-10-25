<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * D9/D2 (dual-dialect architecture plan §4.3/§4.8): the dialect-specific half of
 * {@see DoctrineQueryBuilder::applyDistinctSubqueryIfNeededAndGetResult()} — the branch that only runs when the
 * query has BOTH joins and a limit/offset (every other case is dialect-agnostic and stays in that method
 * unchanged). Real failure this seam fixes: `syntax error at or near "INNER"` on PostgreSQL, from MariaDB's regex
 * SQL-text rewrite ({@see MariaDbDistinctSubqueryPagination}) being run against PostgreSQL-shaped SQL (quoted
 * identifiers, no backtick-based alias matching) by a list/expand endpoint that combines filters/ordering with a
 * limited, joined query.
 *
 * One instance per {@see \DDD\Domain\Base\Repo\DB\Dialect\SqlDialect} (`pagination()`), stateless, resolved per
 * call like every other dialect collaborator (plan rule 3: never process-global).
 */
interface PaginationStrategy
{
    /**
     * Runs the query builder's current DQL as ONE raw SQL statement that returns exactly `maxResults` distinct
     * root entities starting at `firstResult`, honouring joins, filters and ordering, then hydrates the result the
     * same way {@see DoctrineQueryBuilder::getMappedResult()} always has.
     *
     * @param DoctrineQueryBuilder $queryBuilder A builder with at least one join AND a limit/offset/maxResults set
     * @param bool $refresh Re-hydrate managed entities from this result instead of returning the identity map's copy
     */
    public function paginate(DoctrineQueryBuilder $queryBuilder, bool $refresh = false): mixed;
}
