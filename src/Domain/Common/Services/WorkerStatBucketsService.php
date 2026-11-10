<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Workers\WorkerStatBucket;
use DDD\Domain\Common\Entities\Workers\WorkerStatBuckets;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerStatBucket;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerStatBuckets;

/**
 * @method WorkerStatBucket find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method WorkerStatBuckets findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBWorkerStatBucket getEntityRepoClassInstance()
 * @method DBWorkerStatBuckets getEntitySetRepoClassInstance()
 */
class WorkerStatBucketsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = WorkerStatBucket::class;
}
