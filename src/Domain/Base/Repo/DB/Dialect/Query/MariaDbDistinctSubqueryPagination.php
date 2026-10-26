<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;

/**
 * §6 build step 5 ("`MariaDbDistinctSubqueryPagination` (today's body moved verbatim)"): the regex-based SQL-text
 * rewrite that used to sit inline in
 * {@see DoctrineQueryBuilder::applyDistinctSubqueryIfNeededAndGetResult()} — relocated here byte-for-byte (only
 * `$this` became `$queryBuilder`) so the T1 MariaDB integration suite keeps passing unchanged. See that method's
 * original docblock (still there) for the full rationale; this class's only job is to be the MariaDB arm of
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

        // Duplicate the main query to create a subquery
        $query = $queryBuilder->getQuery();
        $query->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true);
        $query->setHint(Query::HINT_INCLUDE_META_COLUMNS, false);
        $mainQuerySQL = $query->getSQL();

        // We require parameterMapping to know the exact order of paramters
        $parameterMappings = $query->getParameterMappings();

        // Parameter handling in DISTINCT-subquery rewrite
        // ---------------------------------------------
        // This method manipulates raw SQL strings (we duplicate the main SQL and inject it as an INNER JOIN subquery).
        // During that process, parts of the SQL (especially the subquery SELECT list) are rewritten.
        // That means the final SQL can contain a different number of placeholders than the original main SQL.
        //
        // Strategy:
        //  1) Convert the original main SQL from positional placeholders (?) to deterministic named placeholders
        //     (:param1, :param2, ...)
        //  2) Build an allocation table :paramN -> value/type
        //  3) After all SQL rewrites/injection, scan the final SQL in appearance order, convert each :paramN
        //     back to '?' and construct the ordered parameters array accordingly.
        //
        // Minimal example:
        //  - Initial SQL:    "SELECT * FROM A WHERE a = ? AND b = ?"
        //  - Initial params: [1 => 'aValue', 2 => 'bValue']
        //  - Named SQL:      "SELECT * FROM A WHERE a = :param1 AND b = :param2"
        //  - Allocation:     ['param1' => 'aValue', 'param2' => 'bValue']
        //  - Final SQL (after injection) might look like:
        //      "SELECT * FROM A INNER JOIN (...) WHERE a = :param1 AND b = :param2"
        //    or it might contain only a subset of params depending on the rewrite.
        //  - We scan the final SQL, rebuild ordered params/types, and replace each :paramN with '?'.
        $positionToParameterName = [];
        foreach ($parameterMappings as $parameterName => $positions) {
            foreach ($positions as $position) {
                $positionToParameterName[(int)$position] = (string)$parameterName;
            }
        }

        $parametersByName = [];
        foreach ($queryBuilder->getParameters() as $parameter) {
            /** @var Parameter $parameter */
            $parametersByName[(string)$parameter->getName()] = $parameter;
        }

        $paramsAllocation = [];
        $typesAllocation = [];
        $placeholderIndex = 0;
        $mainQuerySQL = (string)preg_replace_callback('/\?/', function () use (
            &$placeholderIndex,
            $positionToParameterName,
            $parametersByName,
            &$paramsAllocation,
            &$typesAllocation,
        ): string {
            $currentIndex = $placeholderIndex;
            $placeholderIndex++;
            $placeholderToken = 'param' . $currentIndex;

            $mappedParameterName = $positionToParameterName[$currentIndex] ?? (string)$currentIndex;
            $mappedParameter = $parametersByName[$mappedParameterName] ?? null;
            if ($mappedParameter) {
                $paramsAllocation[$placeholderToken] = $mappedParameter->getValue();
                $typesAllocation[$placeholderToken] = $mappedParameter->getType();
            } else {
                // Fallback: keep placeholder resolvable; execution will fail if a required value is missing.
                $paramsAllocation[$placeholderToken] = null;
                $typesAllocation[$placeholderToken] = null;
            }

            return ':' . $placeholderToken;
        }, $mainQuerySQL);

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
        preg_match($mainEntityAliasPattern, $mainQuerySQL, $matches, PREG_OFFSET_CAPTURE);

        if (isset($matches[0][1])) {
            // Adjust the position to be after the entire matched string
            $position = (int)$matches[0][1] + strlen($matches[0][0]);
        } else {
            // If the pattern is not found, set to the end of the string
            $position = strlen($mainQuerySQL);
        }

        // Inject the subquery with an inner join after FROM <MainTable> <alias> statement
        $joinClause = " INNER JOIN ($subquerySQL) AS subquery ON $mainEntityAlias.id = subquery.id ";
        $combinedSQL = substr_replace($mainQuerySQL, $joinClause, $position, 0);

        // Rebuild ordered parameters by scanning the final SQL for :paramN placeholders.
        // Replace placeholders back to positional ? and build $orderedParameters/$types in appearance order.
        $orderedParameters = [];
        $types = [];
        $combinedSQL = (string)preg_replace_callback('/:(?P<token>param\d+)/', function (array $match) use (
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
}
