<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\SpatialRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\SpatialRows\DBSpatialRows;
use DDD\Tests\Integration\Fixtures\Services\SpatialRowsService;

/**
 * @property SpatialRow[] $elements
 * @method SpatialRow|null first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBSpatialRows::class)]
class SpatialRows extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = SpatialRowsService::class;
}
