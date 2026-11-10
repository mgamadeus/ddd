<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Workers\WorkerTransportSample;
use DDD\Domain\Common\Entities\Workers\WorkerTransportSamples;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerTransportSample;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerTransportSamples;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * @method WorkerTransportSample find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method WorkerTransportSamples findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBWorkerTransportSample getEntityRepoClassInstance()
 * @method DBWorkerTransportSamples getEntitySetRepoClassInstance()
 */
class WorkerTransportSamplesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = WorkerTransportSample::class;

    /** Internal reads set their own limit: the set's default `top` is for API lists */
    public const int MAX_ROWS_PER_QUERY = 200000;

    /**
     * Merges the samples into the stored ones: one multi-row upsert on (transport, bucketStart) — maxima kept, last
     * values replaced. The set must hold each key once.
     */
    public function mergeWorkerTransportSamples(WorkerTransportSamples $workerTransportSamples): void
    {
        if ($workerTransportSamples->isEmpty()) {
            return;
        }
        $this->batchUpdate($workerTransportSamples);
    }

    /** @return int Deleted samples that started before $before */
    public function deleteWorkerTransportSamplesOlderThan(DateTime $before): int
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
     * The samples of a time window, oldest first; without a transport those of all transports.
     */
    public function findWorkerTransportSamplesInWindow(DateTime $from, DateTime $to, ?string $transport = null): WorkerTransportSamples
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
        return $repoClass->find($queryBuilder, false) ?? new WorkerTransportSamples();
    }

    /** @return DateTime|null When the sampler command last wrote a sample; null = never (within the retention) */
    public function getLastSampledByCommandAt(): ?DateTime
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $lastSampledByCommandAt = $repoClass::createQueryBuilder(true)
            ->select("MAX({$alias}.lastSampledByCommandAt)")
            ->getQuery()->getSingleScalarResult();
        return $lastSampledByCommandAt ? DateTime::fromString((string)$lastSampledByCommandAt) : null;
    }
}
