<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * PostgreSQL rendering of the generic `VEC_DISTANCE` DQL name MariaDB registers via
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Distance} (`VEC_DISTANCE()`, MariaDB's own default metric).
 * Mapped to pgvector's `<->` (L2), matching {@see PostgresEuclideanDistance} — documented as the chosen default;
 * a caller needing cosine semantics uses `COSINE_DISTANCE` explicitly, on both engines. PostgreSQL only;
 * registered by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `VEC_DISTANCE(col, queryVec)`
 * SQL: `(col <-> queryVec)`
 */
class PostgresVecDistance extends FunctionNode
{
    protected mixed $field;

    protected mixed $vector;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->field = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->vector = $parser->StringExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return '(' . $this->field->dispatch($sqlWalker) . ' <-> ' . $this->vector->dispatch($sqlWalker) . ')';
    }
}
