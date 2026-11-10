<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerStatBuckets;
use DDD\Domain\Common\Services\WorkerStatBucketsService;

/**
 * @property WorkerStatBucket[] $elements
 * @method WorkerStatBucket getByUniqueKey(string $uniqueKey)
 * @method WorkerStatBucket[] getElements()
 * @method WorkerStatBucket first()
 * @method static WorkerStatBucketsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBWorkerStatBuckets::class)]
#[QueryOptions(top: 1000)]
class WorkerStatBuckets extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = WorkerStatBucketsService::class;
}
