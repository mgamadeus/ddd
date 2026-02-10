<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_PolyFromText(wkt [, srid])
 * SQL: ST_PolyFromText(wkt [, srid])
 */
class StPolyFromText extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_PolyFromText';
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
