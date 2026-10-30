<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * D6/Mig §3.8: PostgreSQL rendering of the `COSINE_DISTANCE` DQL name MariaDB registers via
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\CosineDistance} (MariaDB's `VEC_DISTANCE_COSINE()`).
 * pgvector exposes cosine distance as the `<=>` operator, which the ANN index (`vector_cosine_ops`) actually
 * serves — a function wrapper would not. PostgreSQL only; registered by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `COSINE_DISTANCE(col, queryVec)`
 * SQL: `(col <=> queryVec)`
 */
class PostgresCosineDistance extends FunctionNode
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
        return '(' . $this->field->dispatch($sqlWalker) . ' <=> ' . $this->vector->dispatch($sqlWalker) . ')';
    }
}
