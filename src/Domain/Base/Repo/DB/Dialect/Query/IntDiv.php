<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Mig §3.6 ("integer division"): MariaDB's `DIV` operator has no PostgreSQL equivalent (`/` on two integers in
 * PostgreSQL already truncates, like `DIV`, but a polymorphic call cannot assume both sides arrived as integers —
 * pdo_pgsql binds untyped, same reasoning as {@see PostgresField}). PostgreSQL only; registered by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `INT_DIV(a, b)`
 * SQL: `(CAST(a AS BIGINT) / CAST(b AS BIGINT))`
 */
class IntDiv extends FunctionNode
{
    protected mixed $dividend;

    protected mixed $divisor;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->dividend = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->divisor = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $dividendSql = $this->dividend->dispatch($sqlWalker);
        $divisorSql = $this->divisor->dispatch($sqlWalker);

        return "(CAST($dividendSql AS BIGINT) / CAST($divisorSql AS BIGINT))";
    }
}
