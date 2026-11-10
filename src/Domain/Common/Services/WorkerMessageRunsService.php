<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRun;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRuns;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerMessageRun;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerMessageRuns;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * @method WorkerMessageRun find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method WorkerMessageRuns findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBWorkerMessageRun getEntityRepoClassInstance()
 * @method DBWorkerMessageRuns getEntitySetRepoClassInstance()
 */
class WorkerMessageRunsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = WorkerMessageRun::class;

    /**
     * Writes the runs with one multi-row insert.
     */
    public function addWorkerMessageRuns(WorkerMessageRuns $workerMessageRuns): void
    {
        if ($workerMessageRuns->isEmpty()) {
            return;
        }
        $this->batchUpdate($workerMessageRuns);
    }

    /**
     * Deletes handled runs that started before $handledBefore and failed ones that started before $failedBefore.
     *
     * @return int Deleted runs
     */
    public function deleteWorkerMessageRunsOlderThan(DateTime $handledBefore, DateTime $failedBefore): int
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $deletedHandled = (int)$repoClass::createQueryBuilder()
            ->delete($repoClass::BASE_ORM_MODEL, $alias)
            ->where("{$alias}.startedAt < :before")->setParameter('before', $handledBefore)
            ->andWhere("{$alias}.outcome = :outcome")->setParameter('outcome', WorkerMessageRun::OUTCOME_HANDLED)
            ->getQuery()->execute();
        $deletedFailed = (int)$repoClass::createQueryBuilder()
            ->delete($repoClass::BASE_ORM_MODEL, $alias)
            ->where("{$alias}.startedAt < :before")->setParameter('before', $failedBefore)
            ->getQuery()->execute();
        return $deletedHandled + $deletedFailed;
    }
}
