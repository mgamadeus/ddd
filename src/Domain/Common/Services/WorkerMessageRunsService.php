<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRun;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRuns;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerMessageRun;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerMessageRuns;

/**
 * @method WorkerMessageRun find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method WorkerMessageRuns findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBWorkerMessageRun getEntityRepoClassInstance()
 * @method DBWorkerMessageRuns getEntitySetRepoClassInstance()
 */
class WorkerMessageRunsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = WorkerMessageRun::class;
}
