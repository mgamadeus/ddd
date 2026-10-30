<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;

/**
 * The regex-based SQL-text rewrite of {@see DoctrineQueryBuilder::applyDistinctSubqueryIfNeededAndGetResult()}: the
 * FULL query (with its WHERE, limit and offset) becomes `INNER JOIN (SELECT DISTINCT <root>.id …) AS subquery`, and the
 * outer query — rendered WITHOUT the WHERE — loads those roots with all their joins. The WHERE selects roots only;
 * a fetch-joined collection is never cut down to the matching children (its own filter sits in the join's ON). This
 * class is the MariaDB arm of
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect::pagination()}, selected via
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::pagination()}'s PostgreSQL sibling,
 * {@see RowNumberPagePagination}.
 */
class MariaDbDistinctSubqueryPagination implements PaginationStrategy
{
    public function paginate(DoctrineQueryBuilder $queryBuilder, bool $refresh = false): mixed
    {
        // Store the original limit and offset, then reset them
        $maxResults = $queryBuilder->getMaxResults();
        $firstResult = $queryBuilder->getFirstResult();
        $queryBuilder->setMaxResults(null);
        $queryBuilder->setFirstResult(null);

        // The FULL query (with its WHERE) is the source of the id subquery: it decides which roots are on the page.
        $query = $queryBuilder->getQuery();
        $query->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true);
        $query->setHint(Query::HINT_INCLUDE_META_COLUMNS, false);
        $fullPaginationSql = $this->allocateNamedPlaceholders($queryBuilder, $query, 'param');
        $mainQuerySQL = $fullPaginationSql->sql;

        // The OUTER query loads the page's roots with their joins WITHOUT the WHERE: the subquery already decided which
        // roots qualify, and re-applying the filter would cut fetch-joined collections down to the matching children
        // (OData: a root filter selects roots; a collection is filtered by its expand's own filter, in the join's ON).
        $outerPaginationSql = $this->buildOuterSqlWithoutWhere($queryBuilder);
        $outerQuerySQL = $outerPaginationSql->sql;
        $paramsAllocation = [...$fullPaginationSql->parametersByToken, ...$outerPaginationSql->parametersByToken];
        $typesAllocation = [...$fullPaginationSql->typesByToken, ...$outerPaginationSql->typesByToken];

        // Scalar select alias preservation for subquery ORDER BY
        // ---------------------------------------------
        // Doctrine may generate scalar expressions in the SELECT list using aliases like "sclr_0".
        // Example (main SQL):
        //   SELECT MATCH(b0_.virtualNameSearch) AGAINST (:param1) AS sclr_0, ... FROM ... ORDER BY sclr_0 ASC
        //
        // Our DISTINCT-subquery rewrite replaces the entire SELECT list in the subquery with:
        //   SELECT DISTINCT <mainAlias>sub.id FROM ...
        // which removes the scalar projection (and therefore the definition of "sclr_0").
        // However the ORDER BY clause remains, resulting in invalid SQL:
        //   SELECT DISTINCT l1_sub.id FROM ... ORDER BY sclr_0 ASC
        //
        // Solution: build a map of scalar alias -> expression from the original main SQL and later
        // expand occurrences of sclr_* in the SUBQUERY ORDER BY to the underlying expression.
        // This keeps the subquery valid without having to keep scalar expressions in the subquery SELECT list.
        $scalarAliasToExpression = [];
        if (preg_match('/^\s*SELECT\s+(?P<select>.*?)\s+FROM\s/si', $mainQuerySQL, $selectMatches)) {
            $selectPart = (string)$selectMatches['select'];
            if (preg_match_all('/(?P<expression>[^,]+?)\s+AS\s+(?P<alias>sclr_\d+)/i', $selectPart, $scalarMatches, PREG_SET_ORDER)) {
                foreach ($scalarMatches as $scalarMatch) {
                    $alias = $scalarMatch['alias'];
                    $expression = trim($scalarMatch['expression']);
                    $scalarAliasToExpression[$alias] = $expression;
                }
            }
        }

        $subquerySQL = $mainQuerySQL;

        // Extract the main entity alias from the main query
        $mainEntityAliasPattern = '/FROM\s+[a-zA-Z0-9_]+\s+([a-zA-Z0-9_]+_)/';
        preg_match($mainEntityAliasPattern, $mainQuerySQL, $matches);
        $mainEntityAlias = $matches[1];

        // Modify the subquery to avoid collisions in aliases and properties
        $subquerySQL = preg_replace('/([a-zA-Z]+[0-9]+_)/', '$1sub', $subquerySQL);
        $subquerySQL = preg_replace(
            '/^\s*SELECT.*?FROM/',
            'SELECT DISTINCT ' . $mainEntityAlias . 'sub.id FROM',
            $subquerySQL
        );

        // Expand scalar aliases (sclr_*) inside subquery ORDER BY.
        // After the SELECT list is stripped, aliases like sclr_0 are no longer defined in the subquery.
        // We replace them with their original expression, but with subquery aliases applied.
        // Example:
        //   ORDER BY sclr_0 ASC
        // becomes:
        //   ORDER BY MATCH(b0_sub.virtualNameSearch) AGAINST (:param1) ASC
        if (!empty($scalarAliasToExpression)) {
            $subquerySQL = (string)preg_replace_callback('/\bsclr_\d+\b/', function (array $match) use ($scalarAliasToExpression): string {
                $alias = (string)$match[0];
                $expression = $scalarAliasToExpression[$alias] ?? null;
                if (!$expression) {
                    return $alias;
                }
                // Apply the same alias suffixing as the subquery SQL rewrite (b0_ -> b0_sub, l1_ -> l1_sub, ...)
                return (string)preg_replace('/([a-zA-Z]+[0-9]+_)/', '$1sub', $expression);
            }, $subquerySQL);
        }

        // Apply limit and offset to the subquery
        if ($maxResults) {
            $subquerySQL .= ' LIMIT ' . $maxResults;
        }
        if ($firstResult) {
            $subquerySQL .= ' OFFSET ' . $firstResult;
        }

        // Determine the position where to inject the join clause based on the pattern
        $matches = [];
        preg_match($mainEntityAliasPattern, $outerQuerySQL, $matches, PREG_OFFSET_CAPTURE);

        if (isset($matches[0][1])) {
            // Adjust the position to be after the entire matched string
            $position = (int)$matches[0][1] + strlen($matches[0][0]);
        } else {
            // If the pattern is not found, set to the end of the string
            $position = strlen($outerQuerySQL);
        }

        // Inject the subquery with an inner join after FROM <MainTable> <alias> statement
        $joinClause = " INNER JOIN ($subquerySQL) AS subquery ON $mainEntityAlias.id = subquery.id ";
        $combinedSQL = substr_replace($outerQuerySQL, $joinClause, $position, 0);

        // Rebuild ordered parameters by scanning the final SQL for :paramN placeholders.
        // Replace placeholders back to positional ? and build $orderedParameters/$types in appearance order.
        $orderedParameters = [];
        $types = [];
        $combinedSQL = (string)preg_replace_callback('/:(?P<token>(?:outer)?param\d+)/', function (array $match) use (
            &$orderedParameters,
            &$types,
            $paramsAllocation,
            $typesAllocation,
        ): string {
            $token = (string)$match['token'];
            $orderedParameters[] = $paramsAllocation[$token] ?? null;
            $types[] = $typesAllocation[$token] ?? null;
            return '?';
        }, $combinedSQL);

        // Execute the combined query
        $connection = $queryBuilder->getEntityManager()->getConnection();
        $result = $connection->executeQuery($combinedSQL, $orderedParameters, $types);

        // The raw SQL above only FETCHES rows; getMappedResult() hydrates them through Doctrine's ObjectHydrator,
        // which consults the identity map and hands back an already-managed instance with its STALE fields unless
        // the hint says otherwise. So a set reload with useEntityRegistrCache=false returned pre-write models on
        // exactly this branch (joins + limit), while the simple branch above was fixed — e.g. a tool call read back
        // as PENDING in the same process after its row had been written to COMPLETED.
        // Map the result to ORM entities
        return $queryBuilder->getMappedResult($result, $refresh);
    }

    /**
     * Renders the query builder's query WITHOUT its WHERE (limit/offset are already reset by the caller) — the outer
     * query of the page — with its own named placeholders (`:outerparamN`), so its bind values never mix with the id
     * subquery's. The builder's WHERE is restored afterwards.
     */
    protected function buildOuterSqlWithoutWhere(DoctrineQueryBuilder $queryBuilder): PaginationSql
    {
        $savedWhere = $queryBuilder->getDQLPart('where');
        $queryBuilder->resetDQLPart('where');
        try {
            $outerQuery = $queryBuilder->getQuery();
            $outerQuery->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true);
            $outerQuery->setHint(Query::HINT_INCLUDE_META_COLUMNS, false);
            return $this->allocateNamedPlaceholders($queryBuilder, $outerQuery, 'outerparam');
        } finally {
            if ($savedWhere !== null) {
                $queryBuilder->add('where', $savedWhere);
            }
        }
    }

    /**
     * The query's SQL with every positional `?` replaced by a deterministic named token (`:<prefix>N`), and the bind
     * value / type per token. Raw SQL rewrites (subquery duplication, alias suffixing) can change how many
     * placeholders survive; the caller rescans the final SQL in appearance order and rebuilds the positional binds.
     *
     * Example: "SELECT * FROM A WHERE a = ? AND b = ?" with [1 => 'x', 2 => 'y'] and prefix "param" becomes
     * "SELECT * FROM A WHERE a = :param0 AND b = :param1" with ['param0' => 'x', 'param1' => 'y'].
     */
    protected function allocateNamedPlaceholders(DoctrineQueryBuilder $queryBuilder, Query $query, string $tokenPrefix): PaginationSql
    {
        // getSQL() parses the query; the parameter mappings exist only after that
        $querySql = $query->getSQL();
        $positionToParameterName = [];
        foreach ($query->getParameterMappings() as $parameterName => $positions) {
            foreach ($positions as $position) {
                $positionToParameterName[(int)$position] = (string)$parameterName;
            }
        }

        $parametersByName = [];
        foreach ($queryBuilder->getParameters() as $parameter) {
            /** @var Parameter $parameter */
            $parametersByName[(string)$parameter->getName()] = $parameter;
        }

        $parametersByToken = [];
        $typesByToken = [];
        $placeholderIndex = 0;
        $sql = (string)preg_replace_callback('/\?/', function () use (
            &$placeholderIndex,
            $positionToParameterName,
            $parametersByName,
            &$parametersByToken,
            &$typesByToken,
            $tokenPrefix,
        ): string {
            $currentIndex = $placeholderIndex;
            $placeholderIndex++;
            $placeholderToken = $tokenPrefix . $currentIndex;

            $mappedParameterName = $positionToParameterName[$currentIndex] ?? (string)$currentIndex;
            $mappedParameter = $parametersByName[$mappedParameterName] ?? null;
            if ($mappedParameter) {
                $parametersByToken[$placeholderToken] = $mappedParameter->getValue();
                $typesByToken[$placeholderToken] = $mappedParameter->getType();
            } else {
                // Fallback: keep placeholder resolvable; execution will fail if a required value is missing.
                $parametersByToken[$placeholderToken] = null;
                $typesByToken[$placeholderToken] = null;
            }

            return ':' . $placeholderToken;
        }, $querySql);

        return new PaginationSql($sql, $parametersByToken, $typesByToken);
    }
}
