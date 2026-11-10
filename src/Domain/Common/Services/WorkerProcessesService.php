<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerProcess;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerProcesses;

/**
 * @method WorkerProcess find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method WorkerProcesses findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBWorkerProcess getEntityRepoClassInstance()
 * @method DBWorkerProcesses getEntitySetRepoClassInstance()
 */
class WorkerProcessesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = WorkerProcess::class;
}
