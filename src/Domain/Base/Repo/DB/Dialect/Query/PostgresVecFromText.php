<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Mig §3.8/D6: PostgreSQL rendering of the `VEC_FROM_TEXT` DQL name MariaDB registers via
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\VecFromText} (MariaDB's binary `VEC_FromText()`). pgvector's
 * `vector` type parses the same bracketed text form directly via a plain cast — no function call needed on
 * PostgreSQL. PostgreSQL only; registered by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `VEC_FROM_TEXT(expr)`
 * SQL: `CAST(expr AS vector)`
 */
class PostgresVecFromText extends FunctionNode
{
    protected mixed $value;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->value = $parser->StringExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'CAST(' . $this->value->dispatch($sqlWalker) . ' AS vector)';
    }
}
