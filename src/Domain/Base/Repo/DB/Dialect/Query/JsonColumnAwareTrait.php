<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST;

/**
 * D8 (dual-dialect architecture plan §4.8/§4.9; Mig §3.2): PostgreSQL's `json` type has no ordering, equality or
 * hash operator, so DQL that reaches a `json`-typed column (every `json` column — including a plain,
 * non-fulltext-indexed Translatable, which is stored as `json`, registered under the Doctrine type name `json` by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()} — `Type::overrideType('json',
 * UnescapedJsonType::class)`) through `ORDER BY`, a comparison/`LIKE`/`IN`/`BETWEEN`, `SELECT DISTINCT` or
 * `GROUP BY` has to go through its text form instead.
 *
 * {@see \DDD\Domain\Base\Entities\QueryOptions\OrderByOptions} and
 * {@see \DDD\Domain\Base\Entities\QueryOptions\FiltersOptions} already handle this for QueryOptions-DRIVEN DQL
 * (`JSON_TEXT(...)` / a 400 at validation time). This trait is the GENERIC, walker-level fallback for DQL that
 * reaches the same columns directly — a raw `$queryBuilder->orderBy("$alias.name")`, a raw
 * `->andWhere("$alias.name = :x")`, a `->distinct()` hydration of an entity with `json` columns — the exact shapes
 * behind the real failures of a consuming app on PostgreSQL: "could not identify an
 * ordering operator for type json" (×9, raw `orderBy` on a Translatable column), "operator does not exist: json =
 * unknown" (a raw equality filter on `Products.name`), and "could not identify an equality operator for type json"
 * (×5, `SELECT DISTINCT` hydrating `OrderItem`, whose `unitPrice`/`optionsPriceDelta` are JSON-persisted
 * `MoneyAmount` value objects — `DoctrineQueryBuilder::distinct()`'s own to-many-LEFT-JOIN dedup, per its docblock
 * at `DoctrineQueryBuilder.php:178-220`).
 *
 * Shared by {@see PostgresSqlWalker} (the default output walker) and {@see PostgresLimitSubqueryOutputWalker} (the
 * ROW_NUMBER id-page walker — its own `walkOrderByItem()` reaches the inner `ORDER BY` that the
 * `ROW_NUMBER() OVER (...)` clause is built from).
 */
trait JsonColumnAwareTrait
{
    /**
     * Whether $expression is a state-field {@see AST\PathExpression} whose Doctrine column type is `json`.
     */
    protected function isJsonColumnPathExpression(mixed $expression): bool
    {
        if (!$expression instanceof AST\PathExpression || $expression->type !== AST\PathExpression::TYPE_STATE_FIELD) {
            return false;
        }

        $class = $this->getMetadataForDqlAlias($expression->identificationVariable);
        $fieldMapping = $class->fieldMappings[$expression->field] ?? null;

        return ($fieldMapping['type'] ?? null) === 'json';
    }

    /**
     * `(CAST($columnSql AS TEXT) COLLATE "C")` — the same SQL {@see JsonText} (`JSON_TEXT(...)` in DQL) renders, so
     * an `ORDER BY` on a raw `json` column sorts its text form byte-for-byte, matching MariaDB's `utf8mb4_bin`
     * `LONGTEXT` order.
     */
    protected function castJsonColumnSqlForOrdering(string $columnSql): string
    {
        return "(CAST($columnSql AS TEXT) COLLATE \"C\")";
    }

    /**
     * `CAST($columnSql AS TEXT)` — the same SQL {@see TextCast} renders. Used for an equality/`LIKE`/`IN`/
     * `BETWEEN`/`DISTINCT`/`GROUP BY` context, where byte-order determinism (the `"C"` collation above) does not
     * matter, only that the operator exists.
     */
    protected function castJsonColumnSqlForComparison(string $columnSql): string
    {
        return "CAST($columnSql AS TEXT)";
    }

    /**
     * `ComparisonExpression`/`InExpression`/`BetweenExpression` all parse their operand as
     * `ArithmeticExpression ::= SimpleArithmeticExpression | "(" Subselect ")"` (`Parser::ComparisonExpression()`/
     * `InExpression()`/`BetweenExpression()`) — unlike `OrderByItem`/`GroupByItem`/`StringExpression` (`LIKE`'s own
     * operand grammar), which hand back a bare {@see AST\PathExpression} directly for a single state field, THESE
     * THREE wrap it one or more layers deep (`ArithmeticExpression` → `SimpleArithmeticExpression` →
     * `ArithmeticTerm` → `ArithmeticFactor` → the `PathExpression`) even when there is only one term/factor and no
     * sign — none of that wrapping collapses away (confirmed empirically: a raw `"$alias.title = :x"` comparison's
     * `leftExpression` is a bare `AST\ArithmeticExpression`, not a `PathExpression`). This unwraps exactly that
     * trivial (single-term, single-factor, unsigned) chain to find the `PathExpression` underneath, so
     * `isJsonColumnPathExpression()` can see it; it returns null for anything else (a real `+`/`-`/`*`/`/` chain,
     * a signed factor, a subselect, a function, a literal), which simply never matches a `json` column.
     */
    protected function unwrapToPathExpression(mixed $node): ?AST\PathExpression
    {
        if ($node instanceof AST\PathExpression) {
            return $node;
        }
        if ($node instanceof AST\ArithmeticExpression && $node->simpleArithmeticExpression !== null) {
            return $this->unwrapToPathExpression($node->simpleArithmeticExpression);
        }
        if ($node instanceof AST\SimpleArithmeticExpression && count($node->arithmeticTerms) === 1) {
            return $this->unwrapToPathExpression($node->arithmeticTerms[0]);
        }
        if ($node instanceof AST\ArithmeticTerm && count($node->arithmeticFactors) === 1) {
            return $this->unwrapToPathExpression($node->arithmeticFactors[0]);
        }
        if ($node instanceof AST\ArithmeticFactor && $node->sign === null) {
            return $this->unwrapToPathExpression($node->arithmeticPrimary);
        }
        return null;
    }

    /** Whether $node is, or trivially wraps ({@see self::unwrapToPathExpression()}), a `json`-typed column. */
    protected function isJsonColumnExpression(mixed $node): bool
    {
        $pathExpression = $this->unwrapToPathExpression($node);

        return $pathExpression !== null && $this->isJsonColumnPathExpression($pathExpression);
    }

    /** One synthetic, already-typed `TYPE_STATE_FIELD` {@see AST\PathExpression} for `$dqlAlias.$fieldName`. */
    protected function newStateFieldPathExpression(string $dqlAlias, string $fieldName): AST\PathExpression
    {
        $pathExpression = new AST\PathExpression(AST\PathExpression::TYPE_STATE_FIELD, $dqlAlias, $fieldName);
        // A manually-constructed PathExpression never goes through the parser's processDeferredPathExpressions(),
        // so ->type stays null unless set explicitly — see DefaultOrderTreeWalker's identical note.
        $pathExpression->type = AST\PathExpression::TYPE_STATE_FIELD;

        return $pathExpression;
    }
}
