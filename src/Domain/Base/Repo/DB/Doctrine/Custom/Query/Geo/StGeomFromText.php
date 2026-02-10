<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_GeomFromText(wkt [, srid])
 * SQL: ST_GeomFromText(wkt [, srid])
 */
class StGeomFromText extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_GeomFromText';
    }

    protected function getMinParameterCount(): int
    {
        return 1;
    }

    protected function getMaxParameterCount(): int
    {
        return 2;
    }
}
