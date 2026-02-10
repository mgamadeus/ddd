<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Base class for custom spatial DQL functions.
 *
 * Supports functions with a fixed number of parameters as well as
 * functions that accept optional trailing parameters (e.g. ST_GeomFromText(wkt[, srid])).
 */
abstract class AbstractSpatialFunction extends FunctionNode
{
    /** @var Node[] */
    private array $args = [];

    /**
     * Returns the SQL function name (e.g. 'ST_GeomFromText').
     */
    abstract protected function getSqlFunctionName(): string;

    /**
     * Minimum number of required parameters.
     */
    abstract protected function getMinParameterCount(): int;

    /**
     * Maximum number of accepted parameters.
     * Override this to differ from getMinParameterCount() for optional-param functions.
     */
    protected function getMaxParameterCount(): int
    {
        return $this->getMinParameterCount();
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $sql = $this->getSqlFunctionName() . '(';

        foreach ($this->args as $key => $arg) {
            if ($key !== 0) {
                $sql .= ', ';
            }

            $sql .= $arg->dispatch($sqlWalker);
        }

        $sql .= ')';

        return $sql;
    }

    public function parse(Parser $parser): void
    {
        $this->args = [];

        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $minParams = $this->getMinParameterCount();
        $maxParams = $this->getMaxParameterCount();

        // Parse required parameters
        for ($i = 0; $i < $minParams; $i++) {
            if ($i !== 0) {
                $parser->match(TokenType::T_COMMA);
            }

            $this->args[] = $parser->ArithmeticPrimary();
        }

        // Parse optional parameters
        for ($i = $minParams; $i < $maxParams; $i++) {
            if (!$parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
                break;
            }

            $parser->match(TokenType::T_COMMA);
            $this->args[] = $parser->ArithmeticPrimary();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
