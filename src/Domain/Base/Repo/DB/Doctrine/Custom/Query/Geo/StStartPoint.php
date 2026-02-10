<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_StartPoint(ls)
 * SQL: ST_StartPoint(ls)
 *
 * Returns the first point of a LineString.
 */
class StStartPoint extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_StartPoint';
    }

    protected function getMinParameterCount(): int
    {
        return 1;
    }
}
