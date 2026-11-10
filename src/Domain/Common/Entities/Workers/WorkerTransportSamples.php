<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerTransportSamples;
use DDD\Domain\Common\Services\WorkerTransportSamplesService;

/**
 * @property WorkerTransportSample[] $elements
 * @method WorkerTransportSample getByUniqueKey(string $uniqueKey)
 * @method WorkerTransportSample[] getElements()
 * @method WorkerTransportSample first()
 * @method static WorkerTransportSamplesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBWorkerTransportSamples::class)]
#[QueryOptions(top: 1000)]
class WorkerTransportSamples extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = WorkerTransportSamplesService::class;
}
