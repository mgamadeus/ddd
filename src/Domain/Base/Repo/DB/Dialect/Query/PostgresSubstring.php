<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * PostgreSQL rendering of Doctrine's BUILT-IN `SUBSTRING` DQL function (real failure of a consuming app on PostgreSQL:
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
 *
 * Real failure (a consuming app, 4 occurrences): `TypeError: Cannot assign Doctrine\ORM\Query\AST\Literal to property
 * …PostgresSubstring::$firstSimpleArithmeticExpression of type SimpleArithmeticExpression` — a bare literal
 * `start`/`length` argument (`SUBSTRING(x, 1, 10)`) never reaches `Parser::SimpleArithmeticExpression()`'s
 * `SimpleArithmeticExpression` wrapper at all: that method's own "Phase 1 AST optimization" comment collapses a
 * single-term expression (a bare literal is exactly one `ArithmeticTerm`) down to the term itself, which bottoms
 * out as a plain `AST\Literal`. Doctrine's OWN built-in `Functions\SubstringFunction` — the class this one
 * shadows under the same DQL name — declares these two properties with NO native type at all (a docblock-only
 * `@var SimpleArithmeticExpression` above a bare `public $firstSimpleArithmeticExpression`), exactly so any node
 * `SimpleArithmeticExpression()` returns can be assigned; `SqlWalker::walkSimpleArithmeticExpression()` already
 * branches on `instanceof AST\SimpleArithmeticExpression` internally and falls back to `walkArithmeticTerm()`
 * for anything else, so no further change is needed once the properties stop demanding the narrower type.
 */
class PostgresSubstring extends FunctionNode
{
    protected Node $stringPrimary;

    /**
     * No native type declaration — deliberately matching Doctrine's own `SubstringFunction` (docblock-only
     * `@var SimpleArithmeticExpression`): `Parser::SimpleArithmeticExpression()` can return ANY node its
     * "Phase 1 AST optimization" collapses a single-term expression down to (`AST\Literal`, `AST\PathExpression`,
     * …), not only `AST\SimpleArithmeticExpression` itself, and `SqlWalker::walkSimpleArithmeticExpression()`
     * already handles every one of them — a native type here would only ever be wrong for some shape.
     *
     * @var mixed
     */
    protected $firstSimpleArithmeticExpression;

    /** @var mixed */
    protected $secondSimpleArithmeticExpression = null;

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
