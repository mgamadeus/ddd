<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * D19/§4.8/Mig §3.5: token-list exact matching for the Radbonus `Tracks.allocations` shape (7 raw/DQL sites) — the
 * column stays a plain whitespace-joined token string on both engines, no normalisation table. MariaDB keeps
 * `MATCH(col) AGAINST (? IN BOOLEAN MODE) > 0` (golden; this DQL name is never registered on MariaDB). PostgreSQL
 * only; registered by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `TOKEN_MATCH(col, :tokens, 'ALL'|'ANY'|'NONE')` — `:tokens` is a DQL array parameter, ONE AST node here;
 * DBAL's list-parameter expansion turns its single placeholder into N placeholders in the rendered `ARRAY[…]`
 * (same mechanism as {@see PostgresField}'s `FIELD(id, :ids)`).
 *
 * SQL: `ALL` -> `string_to_array(col, ' ') @> CAST(ARRAY[…] AS text[])`; `ANY` -> `… && …`;
 *      `NONE` -> `NOT (… && …)`.
 *
 * **An empty token list is the caller's responsibility, not this class's**: Mig §3.5 says "an empty list renders
 * `1 = 0` on both", but a DQL AST node cannot see the bound parameter's PHP array at parse/render time (only at
 * execution, after SQL text is fixed) to special-case it. A caller with a possibly-empty token list
 * must check for that in PHP and substitute a plain `1 = 0` filter instead of calling `TOKEN_MATCH` — `CAST(ARRAY[]
 * AS text[]) @> …` would otherwise be vacuously true for `ALL`/`ANY` (an empty array is a subset of everything).
 */
class TokenMatch extends FunctionNode
{
    protected mixed $column;

    protected mixed $tokens;

    protected string $mode;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->column = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->tokens = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->mode = (string)($parser->StringPrimary()->value ?? '');
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $columnSql = $this->column->dispatch($sqlWalker);
        $tokensSql = $this->tokens->dispatch($sqlWalker);
        $arraySql = "CAST(ARRAY[$tokensSql] AS text[])";
        $tokenArray = "string_to_array($columnSql, ' ')";

        return match ($this->mode) {
            'ALL' => "($tokenArray @> $arraySql)",
            'ANY' => "($tokenArray && $arraySql)",
            'NONE' => "(NOT ($tokenArray && $arraySql))",
            default => throw new InternalErrorException(
                "TOKEN_MATCH: unknown mode \"$this->mode\" (expected ALL|ANY|NONE)."
            ),
        };
    }
}
