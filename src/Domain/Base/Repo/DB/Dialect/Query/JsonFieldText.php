<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * D8 / Mig §3.6 ("string functions on JSON / datetime" — `LOWER(address)`-style access into one JSON key): the
 * text value of one key of a `json` column, compared case-insensitively like MariaDB's `JSON_UNQUOTE(JSON_EXTRACT(…))`
 * read through a case-insensitive collation did. PostgreSQL only; registered by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `JSON_FIELD_TEXT(expr, key)`
 * SQL: `((expr ->> key) COLLATE "ddd_ci_ai")`
 */
class JsonFieldText extends FunctionNode
{
    protected mixed $expression;

    protected mixed $key;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->key = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $expressionSql = $this->expression->dispatch($sqlWalker);
        $keySql = $this->key->dispatch($sqlWalker);

        return "(($expressionSql ->> $keySql) COLLATE \"ddd_ci_ai\")";
    }
}
