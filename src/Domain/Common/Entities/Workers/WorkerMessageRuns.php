<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerMessageRuns;
use DDD\Domain\Common\Services\WorkerMessageRunsService;

/**
 * @property WorkerMessageRun[] $elements
 * @method WorkerMessageRun getByUniqueKey(string $uniqueKey)
 * @method WorkerMessageRun[] getElements()
 * @method WorkerMessageRun first()
 * @method static WorkerMessageRunsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBWorkerMessageRuns::class)]
#[QueryOptions(top: 50)]
class WorkerMessageRuns extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = WorkerMessageRunsService::class;
}
