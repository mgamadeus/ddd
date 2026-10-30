<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Mig §3.6 ("string functions on JSON / datetime"): `SUBSTRING(closedAt, 1, 16)` grouped minute buckets
 * (`InsightsSalesService.php:94`). PostgreSQL only; registered by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `TS_MINUTE_KEY(expr)`
 * SQL: `to_char(expr, 'YYYY-MM-DD HH24:MI')`
 */
class TsMinuteKey extends FunctionNode
{
    protected mixed $expression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $expressionSql = $this->expression->dispatch($sqlWalker);

        return "to_char($expressionSql, 'YYYY-MM-DD HH24:MI')";
    }
}
