<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerProcesses;
use DDD\Domain\Common\Services\WorkerProcessesService;

/**
 * @property WorkerProcess[] $elements
 * @method WorkerProcess getByUniqueKey(string $uniqueKey)
 * @method WorkerProcess[] getElements()
 * @method WorkerProcess first()
 * @method static WorkerProcessesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBWorkerProcesses::class)]
#[QueryOptions(top: 50)]
class WorkerProcesses extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = WorkerProcessesService::class;
}
