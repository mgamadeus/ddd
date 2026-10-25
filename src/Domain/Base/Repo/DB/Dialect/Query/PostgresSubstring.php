<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\AST\SimpleArithmeticExpression;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * PostgreSQL rendering of Doctrine's BUILT-IN `SUBSTRING` DQL function (real failure, Dineful on PostgreSQL:
 * `function pg_catalog.substring(timestamp without time zone, integer, integer) does not exist` — a date-key trick,
 * `SUBSTRING(o0_.someDatetime, 1, 10)`, used where Mig §3.6 / architecture §4.8 would prefer `TS_DAY_KEY`/
 * `TS_MINUTE_KEY`/`DATE_BUCKET`, but the live call sites still use `SUBSTRING` directly). MariaDB's `SUBSTR`/
 * `SUBSTRING` coerces any scalar — datetime included — to text implicitly; PostgreSQL's `substring()` has no
 * timestamp overload. Registering this under the SAME DQL name `SUBSTRING` wins over Doctrine's built-in
 * `Functions\SubstringFunction` (`Parser::FunctionDeclaration()` checks custom functions first), so no call site
 * changes; it mirrors that class's grammar exactly and only adds the explicit text cast, which is a no-op when the
 * expression already is text. MariaDB keeps the built-in, untouched (golden unaffected). PostgreSQL only;
 * registered by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `SUBSTRING(expr, start [, length])` (unchanged grammar)
 * SQL: `SUBSTRING(CAST(expr AS TEXT) FROM start [FOR length])`
 */
class PostgresSubstring extends FunctionNode
{
    protected Node $stringPrimary;

    protected SimpleArithmeticExpression $firstSimpleArithmeticExpression;

    protected ?SimpleArithmeticExpression $secondSimpleArithmeticExpression = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->stringPrimary = $parser->StringPrimary();

        $parser->match(TokenType::T_COMMA);

        $this->firstSimpleArithmeticExpression = $parser->SimpleArithmeticExpression();

        $lexer = $parser->getLexer();
        if ($lexer->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->secondSimpleArithmeticExpression = $parser->SimpleArithmeticExpression();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $stringSql = 'CAST(' . $sqlWalker->walkStringPrimary($this->stringPrimary) . ' AS TEXT)';
        $startSql = $sqlWalker->walkSimpleArithmeticExpression($this->firstSimpleArithmeticExpression);

        if ($this->secondSimpleArithmeticExpression === null) {
            return "SUBSTRING($stringSql FROM $startSql)";
        }

        $lengthSql = $sqlWalker->walkSimpleArithmeticExpression($this->secondSimpleArithmeticExpression);

        return "SUBSTRING($stringSql FROM $startSql FOR $lengthSql)";
    }
}
