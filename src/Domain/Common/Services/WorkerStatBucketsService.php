<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Common\Entities\Workers\WorkerTransportThroughputs;
use DDD\Domain\Common\Entities\Workers\WorkerThroughputWindows;
use DDD\Domain\Common\Entities\Workers\WorkerStatBucket;
use DDD\Domain\Common\Entities\Workers\WorkerMessageClassStat;
use DDD\Domain\Common\Entities\Workers\WorkerMessageClassStats;
use DDD\Domain\Common\Entities\Workers\WorkerStatBuckets;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistories;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistory;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerStatBucket;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerStatBuckets;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * @method WorkerStatBucket find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method WorkerStatBuckets findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBWorkerStatBucket getEntityRepoClassInstance()
 * @method DBWorkerStatBuckets getEntitySetRepoClassInstance()
 */
class WorkerStatBucketsService extends WorkerMonitoringEntitiesService
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

    /**
     * What the workers did in a window as sequences per transport (all message classes together): handled, thrown,
     * caught, failed, redelivered, durationAverageMs, durationMaxMs, waitAverageMs, waitMaxMs. A transport without a
     * bucket in the window is not in the result. The duration sequences have no value (null) in a step without a
     * handled message, the wait sequences none in a step without a message that carried its dispatch time.
     *
     * @param string|null $transport Only this transport
     */
    public function findStatSequencesByTransport(DateTime $from, DateTime $to, int $stepSeconds, ?string $transport = null): WorkerTransportHistories
    {
        $properties = ['transport', 'bucketStart', 'handledCount', 'thrownCount', 'caughtCount', 'redeliveredCount', 'durationTotalMs', 'durationMaxMs', 'waitCount', 'waitTotalMs', 'waitMaxMs'];
        $sumsByTransport = [];
        foreach ($this->streamRowsInWindow('bucketStart', $properties, $from, $to, $transport) as $row) {
            $stepStart = $this->getStepStart($row['bucketStart'], $stepSeconds);
            $sums = &$sumsByTransport[(string)$row['transport']];
            foreach (['handledCount', 'thrownCount', 'caughtCount', 'redeliveredCount', 'durationTotalMs', 'waitCount', 'waitTotalMs'] as $summedProperty) {
                $sums[$summedProperty][$stepStart] = ($sums[$summedProperty][$stepStart] ?? 0) + (int)$row[$summedProperty];
            }
            foreach (['durationMaxMs', 'waitMaxMs'] as $maximumProperty) {
                $sums[$maximumProperty][$stepStart] = max($sums[$maximumProperty][$stepStart] ?? 0, (int)$row[$maximumProperty]);
            }
            unset($sums);
        }
        $workerTransportHistories = new WorkerTransportHistories();
        foreach ($sumsByTransport as $transportName => $sums) {
            $failed = [];
            $durationAverage = [];
            $durationMaximum = [];
            $waitAverage = [];
            $waitMaximum = [];
            foreach ($sums['handledCount'] as $stepStart => $handledCount) {
                $failed[$stepStart] = $sums['thrownCount'][$stepStart] + $sums['caughtCount'][$stepStart];
                if ($handledCount > 0) {
                    $durationAverage[$stepStart] = intdiv($sums['durationTotalMs'][$stepStart], $handledCount);
                    $durationMaximum[$stepStart] = $sums['durationMaxMs'][$stepStart];
                }
                if ($sums['waitCount'][$stepStart] > 0) {
                    $waitAverage[$stepStart] = intdiv($sums['waitTotalMs'][$stepStart], $sums['waitCount'][$stepStart]);
                    $waitMaximum[$stepStart] = $sums['waitMaxMs'][$stepStart];
                }
            }
            $workerTransportHistory = $workerTransportHistories->getOrCreateByTransport((string)$transportName);
            foreach ([
                WorkerTransportHistory::SEQUENCE_HANDLED => $sums['handledCount'],
                WorkerTransportHistory::SEQUENCE_THROWN => $sums['thrownCount'],
                WorkerTransportHistory::SEQUENCE_CAUGHT => $sums['caughtCount'],
                WorkerTransportHistory::SEQUENCE_FAILED => $failed,
                WorkerTransportHistory::SEQUENCE_REDELIVERED => $sums['redeliveredCount'],
                WorkerTransportHistory::SEQUENCE_DURATION_AVERAGE_MS => $durationAverage,
                WorkerTransportHistory::SEQUENCE_DURATION_MAX_MS => $durationMaximum,
                WorkerTransportHistory::SEQUENCE_WAIT_AVERAGE_MS => $waitAverage,
                WorkerTransportHistory::SEQUENCE_WAIT_MAX_MS => $waitMaximum,
            ] as $sequenceName => $valuesByStepStart) {
                $workerTransportHistory->addSequence($sequenceName, $from, $to, $stepSeconds, $valuesByStepStart);
            }
        }
        return $workerTransportHistories;
    }

    /**
     * What the workers of a transport did per message class in a window, the class with the most messages first.
     */
    public function findWorkerMessageClassStats(DateTime $from, DateTime $to, string $transport): WorkerMessageClassStats
    {
        $properties = ['messageClass', 'handledCount', 'thrownCount', 'caughtCount', 'redeliveredCount', 'durationTotalMs', 'durationMaxMs', 'waitCount', 'waitTotalMs', 'waitMaxMs', 'lastErrorClass', 'lastErrorMessage', 'lastErrorAt'];
        /** @var WorkerMessageClassStat[] $statsByMessageClass */
        $statsByMessageClass = [];
        $durationTotals = [];
        $waitTotals = [];
        $waitCounts = [];
        foreach ($this->streamRowsInWindow('bucketStart', $properties, $from, $to, $transport) as $row) {
            $messageClass = (string)$row['messageClass'];
            $workerMessageClassStat = $statsByMessageClass[$messageClass] ??= new WorkerMessageClassStat($messageClass);
            $workerMessageClassStat->handledCount += (int)$row['handledCount'];
            $workerMessageClassStat->thrownCount += (int)$row['thrownCount'];
            $workerMessageClassStat->caughtCount += (int)$row['caughtCount'];
            $workerMessageClassStat->redeliveredCount += (int)$row['redeliveredCount'];
            $workerMessageClassStat->durationMaxMs = max($workerMessageClassStat->durationMaxMs, (int)$row['durationMaxMs']);
            $durationTotals[$messageClass] = ($durationTotals[$messageClass] ?? 0) + (int)$row['durationTotalMs'];
            $waitTotals[$messageClass] = ($waitTotals[$messageClass] ?? 0) + (int)$row['waitTotalMs'];
            $waitCounts[$messageClass] = ($waitCounts[$messageClass] ?? 0) + (int)$row['waitCount'];
            if ((int)$row['waitCount'] > 0) {
                $workerMessageClassStat->waitMaxMs = max($workerMessageClassStat->waitMaxMs ?? 0, (int)$row['waitMaxMs']);
            }
            if ($row['lastErrorAt'] !== null) {
                // rows arrive oldest first: the last one with an error wins
                $workerMessageClassStat->lastErrorClass = $row['lastErrorClass'] !== null ? (string)$row['lastErrorClass'] : null;
                $workerMessageClassStat->lastErrorMessage = $row['lastErrorMessage'] !== null ? (string)$row['lastErrorMessage'] : null;
                $workerMessageClassStat->lastErrorAt = DateTime::fromTimestamp($this->getStepStart($row['lastErrorAt'], 1));
            }
        }
        usort($statsByMessageClass, static fn(WorkerMessageClassStat $left, WorkerMessageClassStat $right): int => $right->handledCount <=> $left->handledCount);
        $workerMessageClassStats = new WorkerMessageClassStats();
        foreach ($statsByMessageClass as $workerMessageClassStat) {
            $messageClass = $workerMessageClassStat->messageClass;
            $workerMessageClassStat->durationAverageMs = $workerMessageClassStat->handledCount > 0
                ? intdiv($durationTotals[$messageClass], $workerMessageClassStat->handledCount)
                : null;
            $workerMessageClassStat->waitAverageMs = $waitCounts[$messageClass] > 0 ? intdiv($waitTotals[$messageClass], $waitCounts[$messageClass]) : null;
            $workerMessageClassStats->add($workerMessageClassStat);
        }
        return $workerMessageClassStats;
    }

    /**
     * What was finished and failed per transport in fixed windows back from $now (handledCount, failedCount). A
     * transport without a bucket in the longest window is not in the result.
     *
     * @param int[] $windowSeconds
     */
    public function findThroughputsByTransport(DateTime $now, array $windowSeconds = WorkerThroughputWindows::WINDOW_SECONDS): WorkerTransportThroughputs
    {
        $workerTransportThroughputs = new WorkerTransportThroughputs();
        foreach ($this->sumRowsPerTransportAndWindow('bucketStart', ['handledCount', 'thrownCount', 'caughtCount'], $now, $windowSeconds) as $row) {
            $workerThroughputWindows = $workerTransportThroughputs->getOrCreateByTransport((string)$row['transport'], $windowSeconds)->throughputWindows;
            foreach ($workerThroughputWindows->getElements() as $workerThroughputWindow) {
                $seconds = $workerThroughputWindow->windowSeconds;
                $workerThroughputWindow->handledCount = (int)$row['handledCount_' . $seconds];
                $workerThroughputWindow->failedCount = (int)$row['thrownCount_' . $seconds] + (int)$row['caughtCount_' . $seconds];
            }
        }
        return $workerTransportThroughputs;
    }
}
