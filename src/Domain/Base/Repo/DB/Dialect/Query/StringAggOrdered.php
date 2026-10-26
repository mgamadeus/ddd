<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Mig §3.6 ("aggregates over booleans, string_agg over ints" — `GROUP_CONCAT(id ORDER BY …)`): mirrors the facade
 * helper `SqlDialect::stringAgg()` (architecture plan §4.3), which wraps the aggregated expression in `ddd_text()`
 * (MariaDB's implicit text form of an int/bool; the `ddd_text()` overloads are created by `ensure()`) and leaves
 * the `ORDER BY` expression untouched.
 * PostgreSQL only; registered by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `STRING_AGG_ORDERED(expr, separator, orderExpr)`
 * SQL: `string_agg(ddd_text(expr), separator ORDER BY orderExpr)`
 */
class StringAggOrdered extends FunctionNode
{
    protected mixed $expression;

    protected mixed $separator;

    protected mixed $orderExpression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->separator = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->orderExpression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $expressionSql = $this->expression->dispatch($sqlWalker);
        $separatorSql = $this->separator->dispatch($sqlWalker);
        $orderSql = $this->orderExpression->dispatch($sqlWalker);

        return "string_agg(ddd_text($expressionSql), $separatorSql ORDER BY $orderSql)";
    }
}
