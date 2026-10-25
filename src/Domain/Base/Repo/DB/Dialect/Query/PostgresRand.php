<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\SimpleArithmeticExpression;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * PostgreSQL rendering of the `RAND` DQL name MariaDB registers via `DoctrineExtensions\Query\Mysql\Rand`
 * (architecture plan §4.8). PostgreSQL's `random()` takes no seed argument (MariaDB's optional `RAND(seed)` has no
 * inline equivalent — `setseed()` is a separate statement); a seeded call renders unseeded `random()`, which is a
 * known, documented deviation with no current caller (RB `DBWorlds.php:66-67`, `DBJournals.php:199` call the
 * unseeded form). PostgreSQL only; registered by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `RAND()` or `RAND(seed)`
 * SQL: `random()`
 */
class PostgresRand extends FunctionNode
{
    protected ?SimpleArithmeticExpression $expression = null;

    public function parse(Parser $parser): void
    {
        $lexer = $parser->getLexer();
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        if ($lexer->lookahead->type !== TokenType::T_CLOSE_PARENTHESIS) {
            $this->expression = $parser->SimpleArithmeticExpression();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'random()';
    }
}
