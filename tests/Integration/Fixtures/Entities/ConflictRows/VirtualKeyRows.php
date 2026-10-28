<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\ConflictRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows\DBVirtualKeyRows;
use DDD\Tests\Integration\Fixtures\Services\VirtualKeyRowsService;

/**
 * @property VirtualKeyRow[] $elements
 * @method VirtualKeyRow|null first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBVirtualKeyRows::class)]
class VirtualKeyRows extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = VirtualKeyRowsService::class;
}
