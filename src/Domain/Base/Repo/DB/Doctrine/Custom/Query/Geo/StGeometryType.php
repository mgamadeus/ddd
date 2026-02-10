<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_GeometryType(g)
 * SQL: ST_GeometryType(g)
 *
 * Returns the geometry type name as a string (e.g. 'POINT', 'LINESTRING').
 */
class StGeometryType extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_GeometryType';
    }

    protected function getMinParameterCount(): int
    {
        return 1;
    }
}
