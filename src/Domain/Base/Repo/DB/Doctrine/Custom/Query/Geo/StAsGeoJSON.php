<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_AsGeoJSON(g)
 * SQL: ST_AsGeoJSON(g)
 *
 * Returns the GeoJSON representation of a geometry.
 */
class StAsGeoJSON extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_AsGeoJSON';
    }

    protected function getMinParameterCount(): int
    {
        return 1;
    }
}
