<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;
use RuntimeException;

/**
 * D2/§4.8 (dual-dialect architecture plan): PostgreSQL's pagination-with-joins arm of
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PaginationStrategy} — one raw SQL round trip, no second DQL query, no
 * `WhereInWalker`. Real failure this fixes: MariaDB's regex SQL-text rewrite
 * ({@see MariaDbDistinctSubqueryPagination}) produces `… INNER JOIN (SELECT DISTINCT …) AS subquery …` against
 * PostgreSQL-shaped SQL and the server answers `syntax error at or near "INNER"` on a list/expand endpoint that
 * combines filters/ordering with a limited, joined query.
 *
 * **Mechanism** (two SQL fragments, assembled in PHP, executed as ONE statement):
 *
 * 1. **Id page.** `$queryBuilder->getQuery()` with `Query::HINT_CUSTOM_OUTPUT_WALKER` overridden to
 *    {@see PostgresLimitSubqueryOutputWalker} renders `SELECT DISTINCT <idAlias> FROM (<inner query with a
 *    ROW_NUMBER() OVER (ORDER BY …) column>) dctrn_result GROUP BY <idAlias> ORDER BY dctrn_minrownum ASC` — via
 *    the ORM's `Exec\SingleSelectExecutor`, which (per {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineParser},
 *    "always takes the executor path") calls `$walker->walkSelectStatement($AST)`, and that method on the stock
 *    `LimitSubqueryOutputWalker` finalises against the WALKER's OWN internally-cloned, limit-reset query — so the
 *    string that comes back has NO `LIMIT`/`OFFSET` yet. This method applies them itself, via the same
 *    `$platform->modifyLimitQuery()` call `SingleSelectSqlFinalizer::finalizeSql()` would have made with the real
 *    values — this is exactly why the architecture plan calls out `walkSelectStatementWithRowNumber()`'s public
 *    visibility as an enabling fact: either entry point ends at the same `modifyLimitQuery()` call with the real
 *    first/max results, and driving it through the ordinary `getSQL()` pipeline needs no manual AST/parser-result
 *    plumbing. The id column's alias (`SELECT DISTINCT <alias> FROM …`) is read back off the rendered SQL text —
 *    the ORM assigns it (`id_0`-style), there is no public accessor for it.
 * 2. **Main query.** The SAME query builder, limit/offset removed (restored afterwards), rendered through the
 *    DEFAULT output walker ({@see PostgresSqlWalker}, the PostgreSQL default — see
 *    {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}): every join, every select column,
 *    the original `ORDER BY` (child order). When the query fetch-joins a collection, the `WHERE` is removed as well
 *    (restored afterwards): the id page already decided which roots are on this page, and NOT reapplying the filter
 *    here is what keeps a to-many expand's filtered-on-child predicate from silently hiding sibling child rows of a
 *    root that qualified through a DIFFERENT child. Any other main query keeps its `WHERE`, so a join that only
 *    filters returns the matching rows, not every child of every root on the page
 *    ({@see DoctrineQueryBuilder::filtersFetchJoinedCollection()}).
 * 3. The id page SQL is injected as `INNER JOIN (<id SQL>) page ON page.<idAlias> = <root>.id` right after the
 *    main query's `FROM <table> <rootAlias>`, and the page rank `page.dctrn_minrownum` (`page.<idAlias>` when the
 *    query has no ORDER BY) is prepended to the main query's own `ORDER BY` (or appended as a new one) so roots
 *    come back in the requested order, ties broken by the existing child order —
 *    `ORDER BY page.dctrn_minrownum, <child order>`. Only the query's own (top-level) ORDER BY is used: one inside
 *    a WHERE subselect or an aggregate belongs to that expression. A grouped query orders by `MIN(<rank>)` — it may
 *    order only by grouped columns or aggregates, and every root has exactly one rank.
 * 4. Both fragments' `?` placeholders are renamed to deterministic named tokens before assembly (mirroring
 *    {@see MariaDbDistinctSubqueryPagination}'s own technique) so the final combined SQL can be rescanned in
 *    appearance order to build one ordered parameter/type array, then run as a SINGLE
 *    `Connection::executeQuery()` call and hydrated via {@see DoctrineQueryBuilder::getMappedResult()} — same
 *    hydration path MariaDB's pagination already uses.
 */
class RowNumberPagePagination implements PaginationStrategy
{
    public function paginate(DoctrineQueryBuilder $queryBuilder, bool $refresh = false): mixed
    {
        $maxResults = $queryBuilder->getMaxResults();
        $firstResult = $queryBuilder->getFirstResult() ?: 0;

        $rootAliases = $queryBuilder->getRootAliases();
        if (count($rootAliases) !== 1) {
            throw new RuntimeException(
                'RowNumberPagePagination: exactly one root alias is supported (D2 scope), got ' . count($rootAliases) . '.'
            );
        }

        $idPageSql = $this->buildIdPageSql($queryBuilder, $maxResults, $firstResult);
        $mainPaginationSql = $this->buildMainSqlWithoutLimit($queryBuilder);

        $combinedSql = $this->injectIdPageJoin(
            $mainPaginationSql->sql,
            $idPageSql->sql,
            $idPageSql->alias,
            $mainPaginationSql->alias,
            !empty($queryBuilder->getDQLPart('groupBy'))
        );

        // Both fragments still carry their own named tokens (`:ddd_id_param_N` / `:ddd_main_param_N`). One final
        // rescan of the now-COMBINED text, in textual appearance order, converts every token back to `?` and
        // builds the single ordered bind array `Connection::executeQuery()` needs.
        $paramsByToken = [...$idPageSql->parametersByToken, ...$mainPaginationSql->parametersByToken];
        $typesByToken = [...$idPageSql->typesByToken, ...$mainPaginationSql->typesByToken];
        $orderedParams = [];
        $orderedTypes = [];
        $finalSql = (string)preg_replace_callback(
            '/:(ddd_(?:id|main)_param_\d+)/',
            function (array $match) use (&$orderedParams, &$orderedTypes, $paramsByToken, $typesByToken): string {
                $token = $match[1];
                $orderedParams[] = $paramsByToken[$token] ?? null;
                $orderedTypes[] = $typesByToken[$token] ?? null;
                return '?';
            },
            $combinedSql
        );

        $connection = $queryBuilder->getEntityManager()->getConnection();
        $result = $connection->executeQuery($finalSql, $orderedParams, $orderedTypes);

        return $queryBuilder->getMappedResult($result, $refresh);
    }

    /** @return PaginationSql The id page's named-token SQL, its id column alias as `alias`, and the bind maps */
    protected function buildIdPageSql(DoctrineQueryBuilder $queryBuilder, ?int $maxResults, int $firstResult): PaginationSql
    {
        $idQuery = $queryBuilder->getQuery();
        $idQuery->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, PostgresLimitSubqueryOutputWalker::class);

        $unlimitedSql = $idQuery->getSQL();
        $platform = $queryBuilder->getEntityManager()->getConnection()->getDatabasePlatform();
        $sql = $platform->modifyLimitQuery($unlimitedSql, $maxResults, $firstResult);

        // `SELECT DISTINCT <idAlias> FROM …` when the inner query has no ORDER BY; with one (the common case —
        // every fixture here has one), LimitSubqueryOutputWalker::createSqlWithRowNumber() ALSO adds a second
        // column (`MIN(dctrn_rownum) AS dctrn_minrownum`), so only the FIRST column (up to its comma or
        // whitespace) is read here — the id alias is always first.
        if (!preg_match('/^\s*SELECT\s+DISTINCT\s+([^\s,]+)/i', $sql, $match)) {
            throw new RuntimeException(
                'RowNumberPagePagination: could not read the id page\'s SELECT DISTINCT column from: ' . $sql
            );
        }
        $idAlias = $match[1];

        return $this->allocateNamedPlaceholders(
            $sql,
            $idQuery->getParameterMappings(),
            $idQuery->getParameters(),
            'ddd_id_param_'
        )->withAlias($idAlias);
    }

    /**
     * The main query without limit/offset; without its WHERE too when the query fetch-joins a collection (see the
     * class docblock, point 2).
     *
     * @return PaginationSql The main query's named-token SQL, its root table alias as `alias`, and the bind maps
     */
    protected function buildMainSqlWithoutLimit(DoctrineQueryBuilder $queryBuilder): PaginationSql
    {
        $savedWhere = $queryBuilder->getDQLPart('where');
        $savedMaxResults = $queryBuilder->getMaxResults();
        $savedFirstResult = $queryBuilder->getFirstResult();

        if ($queryBuilder->filtersFetchJoinedCollection()) {
            $queryBuilder->resetDQLPart('where');
        }
        $queryBuilder->setMaxResults(null);
        $queryBuilder->setFirstResult(null);

        try {
            $mainQuery = $queryBuilder->getQuery();
            // Mirrors MariaDbDistinctSubqueryPagination's own hints on the equivalent "full column" render.
            $mainQuery->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true);
            $mainQuery->setHint(Query::HINT_INCLUDE_META_COLUMNS, false);
            $sql = $mainQuery->getSQL();
            $parameterMappings = $mainQuery->getParameterMappings();
            $parameters = $mainQuery->getParameters();
        } finally {
            if ($savedWhere !== null) {
                $queryBuilder->add('where', $savedWhere);
            }
            $queryBuilder->setMaxResults($savedMaxResults);
            $queryBuilder->setFirstResult($savedFirstResult);
        }

        if (!preg_match('/\bFROM\s+"?[A-Za-z0-9_]+"?\s+([A-Za-z0-9_]+)/', $sql, $match)) {
            throw new RuntimeException(
                'RowNumberPagePagination: could not read the main query\'s root table alias from: ' . $sql
            );
        }
        $rootTableAlias = $match[1];

        return $this->allocateNamedPlaceholders(
            $sql,
            $parameterMappings,
            $parameters,
            'ddd_main_param_'
        )->withAlias($rootTableAlias);
    }

    /**
     * Injects `INNER JOIN (<idSql>) page ON page.<idAlias> = <rootTableAlias>.id` right after the main query's
     * `FROM <table> <rootTableAlias>`, and sorts by the page rank first — `page.dctrn_minrownum` when the id page
     * carries one, `page.<idAlias>` otherwise (the existing `ORDER BY`, if any, becomes the tie-breaker).
     */
    protected function injectIdPageJoin(
        string $mainSql,
        string $idSql,
        string $idAlias,
        string $rootTableAlias,
        bool $isGrouped = false
    ): string {
        // The ORDER BY rewrite MUST run on $mainSql alone, before the id-page SQL is embedded: $idSql carries its
        // OWN inner `ROW_NUMBER() OVER (ORDER BY …)` clause, and a single `/ORDER BY/` rescan over the COMBINED
        // text would hit THAT one first (it appears first, textually, once the join is injected right after
        // $mainSql's `FROM`) — corrupting it with `page.<idAlias>` where `page` is not yet in scope ("missing
        // FROM-clause entry for table \"page\"").
        // The id page ranks its roots by the requested ORDER BY in `dctrn_minrownum` (present whenever the query
        // has one); sorting by the root id instead would return the page's roots in id order.
        $pageRankColumn = preg_match('/\bAS\s+dctrn_minrownum\b/i', $idSql) ? 'page.dctrn_minrownum' : "page.$idAlias";
        // A grouped main query may order only by grouped columns or aggregates (PostgreSQL: SQLSTATE 42803); the
        // page join gives every root exactly one rank, so MIN() is that rank.
        $pageOrder = $isGrouped ? "MIN($pageRankColumn)" : $pageRankColumn;
        // Only the main query's own (top-level) ORDER BY: one inside a WHERE subselect or an aggregate
        // (`string_agg(… ORDER BY …)`) belongs to that expression, and the page is not in its scope.
        $orderByListOffset = $this->findTopLevelOrderByListOffset($mainSql);
        if ($orderByListOffset !== null) {
            $mainSql = substr($mainSql, 0, $orderByListOffset) . $pageOrder . ', ' . substr($mainSql, $orderByListOffset);
        } else {
            $mainSql .= ' ORDER BY ' . $pageOrder;
        }

        // The root table alias is unique within the query, so the first `FROM <table> <rootAlias>` is the main
        // query's own — a subselect on the same table carries another alias.
        $fromPattern = '/\bFROM\s+"?[A-Za-z0-9_]+"?\s+' . preg_quote($rootTableAlias, '/') . '\b/';
        $joinClause = " INNER JOIN ($idSql) page ON page.$idAlias = $rootTableAlias.id";

        $sql = preg_replace_callback($fromPattern, static fn(array $m): string => $m[0] . $joinClause, $mainSql, 1);
        if ($sql === null) {
            throw new RuntimeException('RowNumberPagePagination: failed to inject the id-page join.');
        }

        return $sql;
    }

    /**
     * Where the column list of the query's own ORDER BY starts: the last `ORDER BY` outside parentheses, string
     * literals and quoted identifiers; null when the query has none.
     */
    protected function findTopLevelOrderByListOffset(string $sql): ?int
    {
        $orderByListOffset = null;
        $parenthesisDepth = 0;
        $sqlLength = strlen($sql);
        for ($position = 0; $position < $sqlLength; $position++) {
            $character = $sql[$position];
            if ($character === "'" || $character === '"') {
                // a quoted literal or identifier; a doubled quote inside it is an escaped quote
                $position++;
                while ($position < $sqlLength) {
                    if ($sql[$position] === $character) {
                        if ($position + 1 < $sqlLength && $sql[$position + 1] === $character) {
                            $position += 2;
                            continue;
                        }
                        break;
                    }
                    $position++;
                }
                continue;
            }
            if ($character === '(') {
                $parenthesisDepth++;
                continue;
            }
            if ($character === ')') {
                $parenthesisDepth = max(0, $parenthesisDepth - 1);
                continue;
            }
            if ($parenthesisDepth === 0
                && ($character === 'O' || $character === 'o')
                && ($position === 0 || !preg_match('/[A-Za-z0-9_]/', $sql[$position - 1]))
                && preg_match('/\GORDER\s+BY\s+/i', $sql, $orderByMatch, 0, $position)) {
                $orderByListOffset = $position + strlen($orderByMatch[0]);
                $position = $orderByListOffset - 1;
            }
        }
        return $orderByListOffset;
    }

    /**
     * Renames every `?` placeholder in `$sql` to a deterministic, uniquely-prefixed named token (`:ddd_id_param_0`
     * style, prefix supplied by the caller so the id-page and main fragments never collide once concatenated), and
     * returns the parameter VALUE/TYPE for each token in textual order — the same two-pass technique
     * {@see MariaDbDistinctSubqueryPagination} uses, extracted here since it is needed for TWO independently
     * rendered fragments instead of one self-referential duplicate.
     *
     * @param array<int|string, list<int>> $parameterMappings DQL parameter name/position -> SQL placeholder positions
     * @param \Doctrine\Common\Collections\Collection<int, Parameter> $parameters
     * @return PaginationSql The named-token SQL and its bind maps; `alias` is left empty for the caller to set
     */
    protected function allocateNamedPlaceholders(string $sql, array $parameterMappings, $parameters, string $tokenPrefix): PaginationSql
    {
        $positionToParameterName = [];
        foreach ($parameterMappings as $parameterName => $positions) {
            foreach ($positions as $position) {
                $positionToParameterName[(int)$position] = (string)$parameterName;
            }
        }

        $parametersByName = [];
        foreach ($parameters as $parameter) {
            /** @var Parameter $parameter */
            $parametersByName[(string)$parameter->getName()] = $parameter;
        }

        $placeholderIndex = 0;
        $params = [];
        $types = [];
        $namedSql = (string)preg_replace_callback('/\?/', function () use (
            &$placeholderIndex,
            $positionToParameterName,
            $parametersByName,
            $tokenPrefix,
            &$params,
            &$types,
        ): string {
            $currentIndex = $placeholderIndex++;
            $token = $tokenPrefix . $currentIndex;

            $mappedParameterName = $positionToParameterName[$currentIndex] ?? (string)$currentIndex;
            $mappedParameter = $parametersByName[$mappedParameterName] ?? null;
            $params[$token] = $mappedParameter?->getValue();
            $types[$token] = $mappedParameter?->getType();

            return ':' . $token;
        }, $sql);

        // The caller concatenates this SQL with another fragment, then rescans the COMBINED text for named
        // tokens in final appearance order to build the ordered bind arrays — see the two call sites in
        // self::paginate(). Returning the SQL plus both token-keyed maps here, not yet ordered-by-position, lets
        // the caller defer that rescan until both fragments are assembled, exactly like the single-fragment
        // MariaDB rewrite does in one pass.
        return new PaginationSql($namedSql, $params, $types);
    }
}
