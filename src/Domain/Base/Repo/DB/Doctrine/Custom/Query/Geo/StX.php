<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_X(p)
 * SQL: ST_X(p)
 *
 * Returns the X coordinate (longitude) of a Point.
 */
class StX extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_X';
    }

    protected function getMinParameterCount(): int
    {
        return 1;
    }
}
