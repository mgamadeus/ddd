<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_Transform(g, srid)
 * SQL: ST_Transform(g, srid)
 *
 * Transforms a geometry from one spatial reference system to another.
 */
class StTransform extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_Transform';
    }

    protected function getMinParameterCount(): int
    {
        return 2;
    }
}
