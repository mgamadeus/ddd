<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_AsText(g)
 * SQL: ST_AsText(g)
 *
 * Returns the WKT representation of a geometry.
 */
class StAsText extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_AsText';
    }

    protected function getMinParameterCount(): int
    {
        return 1;
    }
}
