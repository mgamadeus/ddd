<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_NumPoints(ls)
 * SQL: ST_NumPoints(ls)
 *
 * Returns the number of points in a LineString.
 */
class StNumPoints extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_NumPoints';
    }

    protected function getMinParameterCount(): int
    {
        return 1;
    }
}
