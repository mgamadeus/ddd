<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: EUCLIDEAN_DISTANCE(col, queryVec)
 * SQL: VEC_DISTANCE_EUCLIDEAN(col, queryVec)
 */
class EuclideanDistance extends FunctionNode
{
    private mixed $field;
    private mixed $vector;

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
        return sprintf(
            'VEC_DISTANCE_EUCLIDEAN(%s, %s)',
            $this->field->dispatch($sqlWalker),
            $this->vector->dispatch($sqlWalker)
        );
    }
}

