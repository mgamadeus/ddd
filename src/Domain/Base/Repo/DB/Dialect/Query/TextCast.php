<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * PostgreSQL-only DQL function (dual-dialect architecture plan
 * `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §4.8/D8). MariaDB coerces any scalar column to
 * text implicitly for `LIKE` (`col LIKE '%term%'` on an int column just works); PostgreSQL has no such implicit
 * cast ("operator does not exist: integer ~~ unknown") and needs an explicit one. Registered only by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()} — MariaDB never sees this DQL name, so
 * its golden output cannot move.
 *
 * DQL: `TEXT_CAST(expr)` or `TEXT_CAST(expr, 'bool')`
 * SQL: `CAST(expr AS TEXT)` — or, for the bool case (D8: "bool CAST(CAST(col AS INT) AS TEXT)" — PostgreSQL has no
 * implicit bool->text cast either), `CAST(CAST(expr AS INT) AS TEXT)`.
 */
class TextCast extends FunctionNode
{
    protected mixed $expression;

    protected ?string $sourceKind = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expression = $parser->ArithmeticPrimary();

        $lexer = $parser->getLexer();
        if ($lexer->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->sourceKind = $parser->StringPrimary()->value ?? null;
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $expressionSql = $this->expression->dispatch($sqlWalker);

        if ($this->sourceKind === 'bool') {
            return "CAST(CAST($expressionSql AS INT) AS TEXT)";
        }

        return "CAST($expressionSql AS TEXT)";
    }
}
