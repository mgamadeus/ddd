<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Workers\WorkerTransportSample;
use DDD\Domain\Common\Entities\Workers\WorkerTransportSamples;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerTransportSample;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerTransportSamples;

/**
 * @method WorkerTransportSample find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method WorkerTransportSamples findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBWorkerTransportSample getEntityRepoClassInstance()
 * @method DBWorkerTransportSamples getEntitySetRepoClassInstance()
 */
class WorkerTransportSamplesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = WorkerTransportSample::class;
}
