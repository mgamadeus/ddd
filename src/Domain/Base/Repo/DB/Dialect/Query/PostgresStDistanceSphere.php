<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Mig §3.8: `ST_Distance_Sphere` is a MariaDB-specific two-argument spherical-distance function; PostGIS's
 * `ST_DistanceSphere` takes an explicit radius, matched here to MariaDB's own default (6 370 986 m) for identical
 * results on both engines. Same DQL name as MariaDB's registration
 * ({@see \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StDistanceSphere}, via `AbstractSpatialFunction`), so
 * no call site changes. PostgreSQL only; registered by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `ST_Distance_Sphere(g1, g2)`
 * SQL: `ST_DistanceSphere(g1, g2, 6370986)`
 */
class PostgresStDistanceSphere extends FunctionNode
{
    protected mixed $geometry1;

    protected mixed $geometry2;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->geometry1 = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->geometry2 = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $g1 = $this->geometry1->dispatch($sqlWalker);
        $g2 = $this->geometry2->dispatch($sqlWalker);

        return "ST_DistanceSphere($g1, $g2, 6370986)";
    }
}
