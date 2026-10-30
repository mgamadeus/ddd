<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST;

/**
 * Comparison rendering shared by {@see PostgresSqlWalker} (every non-paginated query) and
 * {@see PostgresLimitSubqueryOutputWalker} (the id page of a paginated one): a `WHERE` reaches either, so both must
 * render it the same way. Two PostgreSQL rules: a `json` column is cast to text for comparison
 * ({@see JsonColumnAwareTrait}), and a boolean column compared with 0/1 gets TRUE/FALSE.
 * Requires {@see JsonColumnAwareTrait} on the using walker.
 */
trait PostgresComparisonTrait
{
    /**
     * {@inheritDoc}
     *
     * D8 (dual-dialect architecture plan §4.8, JsonColumnAwareTrait): `walkArithmeticExpression`/
     * `walkInputParameter`/`walkFunction`/`walkLiteral` — every method the three overrides below call — are
     * `public`; none touches a property {@see \Doctrine\ORM\Query\SqlWalker} declares `private` (`$conn`,
     * `$queryComponents`, …), so each delegates to `parent::` for the one branch that would (an unparsed non-`Node`
     * operand; a string `$stringExpression` result-variable reference) — neither reachable from ordinary parsed
     * DQL on a `json` column.
     */
    public function walkComparisonExpression($compExpr)
    {
        $leftExpr = $compExpr->leftExpression;
        $rightExpr = $compExpr->rightExpression;

        if (!$leftExpr instanceof AST\Node || !$rightExpr instanceof AST\Node) {
            return parent::walkComparisonExpression($compExpr);
        }

        $leftSql = $this->isJsonColumnExpression($leftExpr)
            ? $this->castJsonColumnSqlForComparison($leftExpr->dispatch($this))
            : $leftExpr->dispatch($this);
        $rightSql = $this->isJsonColumnExpression($rightExpr)
            ? $this->castJsonColumnSqlForComparison($rightExpr->dispatch($this))
            : $rightExpr->dispatch($this);

        // A boolean column compared with the integer literal 0 or 1 (`x.archived != 1`, `x.isActive = 0`): MariaDB's
        // BOOL is TINYINT(1), so app DQL is full of these; PostgreSQL has no boolean = integer operator. Render the
        // literal as FALSE/TRUE — same truth value, both engines' semantics identical for 0/1.
        if ($this->isBooleanColumnExpression($leftExpr) && ($literal = $this->unwrapToBinaryIntegerLiteral($rightExpr)) !== null) {
            $rightSql = $literal === 1 ? 'TRUE' : 'FALSE';
        } elseif ($this->isBooleanColumnExpression($rightExpr) && ($literal = $this->unwrapToBinaryIntegerLiteral($leftExpr)) !== null) {
            $leftSql = $literal === 1 ? 'TRUE' : 'FALSE';
        }

        return $leftSql . ' ' . $compExpr->operator . ' ' . $rightSql;
    }

    /** Whether $node is, or trivially wraps, a state field whose Doctrine type is `boolean`. */
    protected function isBooleanColumnExpression(mixed $node): bool
    {
        $pathExpression = $this->unwrapToPathExpression($node);
        if ($pathExpression === null || $pathExpression->type !== AST\PathExpression::TYPE_STATE_FIELD) {
            return false;
        }
        $class = $this->getMetadataForDqlAlias($pathExpression->identificationVariable);

        return (($class->fieldMappings[$pathExpression->field] ?? [])['type'] ?? null) === 'boolean';
    }

    /** 0 or 1 when $node is (or trivially wraps) the numeric literal 0 or 1, else null. */
    protected function unwrapToBinaryIntegerLiteral(mixed $node): ?int
    {
        while (true) {
            if ($node instanceof AST\Literal) {
                return $node->type === AST\Literal::NUMERIC && in_array((string)$node->value, ['0', '1'], true)
                    ? (int)$node->value
                    : null;
            }
            if ($node instanceof AST\ArithmeticExpression && $node->simpleArithmeticExpression !== null) {
                $node = $node->simpleArithmeticExpression;
            } elseif ($node instanceof AST\SimpleArithmeticExpression && count($node->arithmeticTerms) === 1) {
                $node = $node->arithmeticTerms[0];
            } elseif ($node instanceof AST\ArithmeticTerm && count($node->arithmeticFactors) === 1) {
                $node = $node->arithmeticFactors[0];
            } elseif ($node instanceof AST\ArithmeticFactor && $node->sign === null) {
                $node = $node->arithmeticPrimary;
            } else {
                return null;
            }
        }
    }

}
