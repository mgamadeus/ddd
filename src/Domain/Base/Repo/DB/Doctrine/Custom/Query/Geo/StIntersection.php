<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_Intersection(g1, g2)
 * SQL: ST_Intersection(g1, g2)
 *
 * Returns the geometry that is the intersection of two geometries.
 */
class StIntersection extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_Intersection';
    }

    protected function getMinParameterCount(): int
    {
        return 2;
    }
}
