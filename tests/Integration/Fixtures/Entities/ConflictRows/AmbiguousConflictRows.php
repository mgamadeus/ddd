<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\ConflictRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows\DBAmbiguousConflictRows;
use DDD\Tests\Integration\Fixtures\Services\AmbiguousConflictRowsService;

/**
 * @property AmbiguousConflictRow[] $elements
 * @method AmbiguousConflictRow|null first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAmbiguousConflictRows::class)]
class AmbiguousConflictRows extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AmbiguousConflictRowsService::class;
}
