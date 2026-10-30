<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * D8 (dual-dialect architecture plan §3/§4.8): `json` has no `=` and no B-tree opclass, so an `orderBy` on a json
 * kind (or a plain Translatable, which is stored as `json`) sorts its text form instead — MariaDB's
 * `utf8mb4_bin` byte order, matched here by casting to text under the deterministic `"C"` collation. PostgreSQL
 * only; registered by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `JSON_TEXT(expr)`
 * SQL: `(CAST(expr AS TEXT) COLLATE "C")`
 */
class JsonText extends FunctionNode
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

        return "(CAST($expressionSql AS TEXT) COLLATE \"C\")";
    }
}
