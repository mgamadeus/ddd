<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_GeomFromGeoJSON(json)
 * SQL: ST_GeomFromGeoJSON(json)
 *
 * Creates a geometry from a GeoJSON representation.
 */
class StGeomFromGeoJSON extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_GeomFromGeoJSON';
    }

    protected function getMinParameterCount(): int
    {
        return 1;
    }
}
