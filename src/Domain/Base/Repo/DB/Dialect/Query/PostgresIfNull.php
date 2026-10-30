<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * PostgreSQL rendering of the `IFNULL` DQL name MariaDB registers via `DoctrineExtensions\Query\Mysql\IfNull`
 * (architecture plan §4.8, Mig §3.6's "raw IFNULL → COALESCE" note applied at the DQL level too). PostgreSQL only;
 * registered by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `IFNULL(a, b)`
 * SQL: `COALESCE(a, b)`
 */
class PostgresIfNull extends FunctionNode
{
    protected mixed $expr1;

    protected mixed $expr2;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expr1 = $parser->ArithmeticExpression();
        $parser->match(TokenType::T_COMMA);
        $this->expr2 = $parser->ArithmeticExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'COALESCE(' . $sqlWalker->walkArithmeticPrimary($this->expr1) . ', '
            . $sqlWalker->walkArithmeticPrimary($this->expr2) . ')';
    }
}
