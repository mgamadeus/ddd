<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_Y(p)
 * SQL: ST_Y(p)
 *
 * Returns the Y coordinate (latitude) of a Point.
 */
class StY extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_Y';
    }

    protected function getMinParameterCount(): int
    {
        return 1;
    }
}
