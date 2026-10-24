<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\CounterRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRows;
use DDD\Tests\Integration\Fixtures\Services\CounterRowsService;

/**
 * @property CounterRow[] $elements
 * @method CounterRow|null first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBCounterRows::class)]
class CounterRows extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = CounterRowsService::class;
}
