<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\ChangeHistoryRows\DBChangeHistoryRows;
use DDD\Tests\Integration\Fixtures\Services\ChangeHistoryRowsService;

/**
 * @property ChangeHistoryRow[] $elements
 * @method ChangeHistoryRow|null first()
 * @method ChangeHistoryRow[] getElements()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBChangeHistoryRows::class)]
class ChangeHistoryRows extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = ChangeHistoryRowsService::class;
}
