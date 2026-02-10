<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_SRID(g)
 * SQL: ST_SRID(g)
 *
 * Returns the spatial reference system identifier (SRID) of a geometry.
 */
class StSrid extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_SRID';
    }

    protected function getMinParameterCount(): int
    {
        return 1;
    }
}
