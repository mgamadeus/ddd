<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Mig §3.6 ("current time is statement time on MariaDB, transaction time on PostgreSQL" — `ar.expiresAt >
 * CURRENT_TIMESTAMP()` would keep an expired right valid for the rest of a transaction): overrides Doctrine's
 * built-in `CURRENT_TIMESTAMP` DQL function (`Parser::$datetimeFunctions`) under the SAME name — custom function
 * registrations win over the built-in map (`Parser::FunctionDeclaration()`), so every existing call site keeps
 * working with no DQL change. MariaDB keeps the built-in (`NOW()`-equivalent, statement time there already) via
 * its unmodified configuration — this class is never registered on MariaDB. PostgreSQL only; registered by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `CURRENT_TIMESTAMP()`
 * SQL: `ddd_now()` (`date_trunc('second', statement_timestamp())::timestamp(0)`, ensure()-declared, §6 step 4a)
 */
class PostgresCurrentTimestamp extends FunctionNode
{
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'ddd_now()';
    }
}
