<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * PostgreSQL sibling of {@see PostgresCosineDistance} for the `COSINE_SIMILARITY` DQL name (MariaDB:
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\CosineSimilarity}, `1 - VEC_DISTANCE_COSINE()`).
 * PostgreSQL only; registered by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `COSINE_SIMILARITY(col, queryVec)`
 * SQL: `(1 - (col <=> queryVec))`
 */
class PostgresCosineSimilarity extends FunctionNode
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
        return '(1 - (' . $this->field->dispatch($sqlWalker) . ' <=> ' . $this->vector->dispatch($sqlWalker) . '))';
    }
}
