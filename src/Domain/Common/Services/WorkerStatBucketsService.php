<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Workers\WorkerStatBucket;
use DDD\Domain\Common\Entities\Workers\WorkerStatBuckets;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerStatBucket;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerStatBuckets;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * @method WorkerStatBucket find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method WorkerStatBuckets findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBWorkerStatBucket getEntityRepoClassInstance()
 * @method DBWorkerStatBuckets getEntitySetRepoClassInstance()
 */
class WorkerStatBucketsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = WorkerStatBucket::class;

    /** Internal reads set their own limit: the set's default `top` is for API lists */
    public const int MAX_ROWS_PER_QUERY = 200000;

    /**
     * Adds the buckets to the stored ones: one multi-row additive upsert on (transport, messageClass, bucketStart).
     * The set must hold each key once.
     */
    public function addWorkerStatBuckets(WorkerStatBuckets $workerStatBuckets): void
    {
        if ($workerStatBuckets->isEmpty()) {
            return;
        }
        $this->batchUpdate($workerStatBuckets);
    }

    /** @return int Deleted buckets that started before $before */
    public function deleteWorkerStatBucketsOlderThan(DateTime $before): int
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        return (int)$repoClass::createQueryBuilder()
            ->delete($repoClass::BASE_ORM_MODEL, $alias)
            ->where("{$alias}.bucketStart < :before")
            ->setParameter('before', $before)
            ->getQuery()->execute();
    }

    /**
     * The buckets of a time window, oldest first; without a transport those of all transports.
     */
    public function findWorkerStatBucketsInWindow(DateTime $from, DateTime $to, ?string $transport = null): WorkerStatBuckets
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder = $repoClass::createQueryBuilder(true)
            ->setMaxResults(self::MAX_ROWS_PER_QUERY)
            ->andWhere("{$alias}.bucketStart >= :from")->setParameter('from', $from)
            ->andWhere("{$alias}.bucketStart <= :to")->setParameter('to', $to)
            ->addOrderBy("{$alias}.bucketStart", 'ASC');
        if ($transport !== null) {
            $queryBuilder->andWhere("{$alias}.transport = :transport")->setParameter('transport', $transport);
        }
        return $repoClass->find($queryBuilder, false) ?? new WorkerStatBuckets();
    }
}
