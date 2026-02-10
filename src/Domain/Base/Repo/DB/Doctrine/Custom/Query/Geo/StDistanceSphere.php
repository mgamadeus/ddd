<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_Distance_Sphere(g1, g2)
 * SQL: ST_Distance_Sphere(g1, g2)
 *
 * Returns the minimum spherical distance between two geometries (Point values).
 */
class StDistanceSphere extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_Distance_Sphere';
    }

    protected function getMinParameterCount(): int
    {
        return 2;
    }
}
