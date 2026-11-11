<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * The length of a value in bytes, the same on both engines: DQL `LENGTH()` counts bytes on MariaDB and characters on
 * PostgreSQL, `OCTET_LENGTH()` counts bytes on both. Registered by both dialects' `configureOrm()`.
 *
 * DQL: `OCTET_LENGTH(expression)`
 * SQL: `OCTET_LENGTH(expression)`
 */
class OctetLength extends FunctionNode
{
    protected mixed $expression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expression = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'OCTET_LENGTH(' . $this->expression->dispatch($sqlWalker) . ')';
    }
}
