<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo;

/**
 * DQL: ST_LineFromText(wkt [, srid])
 * SQL: ST_LineFromText(wkt [, srid])
 */
class StLineFromText extends AbstractSpatialFunction
{
    protected function getSqlFunctionName(): string
    {
        return 'ST_LineFromText';
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
