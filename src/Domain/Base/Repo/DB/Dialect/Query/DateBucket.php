<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * Mirrors the facade helper `SqlDialect::dateBucket()` (architecture plan §4.3) as a DQL function for use inside a
 * query instead of a raw-SQL caller. Renders the same `'YYYY-MM-DD'`-family bucket key on both engines (only
 * PostgreSQL needs a registration — MariaDB's `DATE_FORMAT` callers stay as they are). PostgreSQL only; registered
 * by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `DATE_BUCKET(expr, 'day'|'week'|'month')`
 * SQL: day -> `to_char(expr, 'YYYY-MM-DD')`; week -> `to_char(date_trunc('week', expr), 'YYYY-MM-DD')`;
 *      month -> `to_char(expr, 'YYYY-MM')`
 */
class DateBucket extends FunctionNode
{
    protected mixed $expression;

    protected string $bucket;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->bucket = strtolower((string)($parser->StringPrimary()->value ?? ''));
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $expressionSql = $this->expression->dispatch($sqlWalker);

        return match ($this->bucket) {
            'day' => "to_char($expressionSql, 'YYYY-MM-DD')",
            'week' => "to_char(date_trunc('week', $expressionSql), 'YYYY-MM-DD')",
            'month' => "to_char($expressionSql, 'YYYY-MM')",
            default => throw new InternalErrorException("DATE_BUCKET: unknown bucket \"$this->bucket\" (expected day|week|month)."),
        };
    }
}
