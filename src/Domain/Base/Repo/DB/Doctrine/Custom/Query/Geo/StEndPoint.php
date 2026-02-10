<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_EndPoint(ls)
 * SQL: ST_EndPoint(ls)
 *
 * Returns the last point of a LineString.
 */
class StEndPoint extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_EndPoint';
    }

    protected function getMinParameterCount(): int
    {
        return 1;
    }
}
