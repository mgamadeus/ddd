<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_PointFromText(wkt [, srid])
 * SQL: ST_PointFromText(wkt [, srid])
 */
class StPointFromText extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_PointFromText';
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
