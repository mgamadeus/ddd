<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\GeographyRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Tests\Integration\Fixtures\Repo\DB\GeographyRows\DBGeographyRows;
use DDD\Tests\Integration\Fixtures\Services\GeographyRowsService;

/**
 * @property GeographyRow[] $elements
 * @method GeographyRow|null first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBGeographyRows::class)]
class GeographyRows extends EntitySet
{
    public const string SERVICE_NAME = GeographyRowsService::class;
}
