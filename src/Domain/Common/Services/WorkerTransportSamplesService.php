<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Common\Entities\Workers\WorkerTransportThroughputs;
use DDD\Domain\Common\Entities\Workers\WorkerThroughputWindows;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistories;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistory;
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
class WorkerTransportSamplesService extends WorkerMonitoringEntitiesService
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

    /**
     * The queue and worker state of a window as sequences per transport: waiting, inFlight, processes — the highest
     * sample of each step; a step without a sample (for waiting: without a count) has no value (null). A transport
     * without a sample in the window is not in the result.
     *
     * @param string|null $transport Only this transport
     */
    public function findSampleSequencesByTransport(DateTime $from, DateTime $to, int $stepSeconds, ?string $transport = null): WorkerTransportHistories
    {
        $sequenceNamesByProperty = [
            'waitingMax' => WorkerTransportHistory::SEQUENCE_WAITING,
            'inFlightMax' => WorkerTransportHistory::SEQUENCE_IN_FLIGHT,
            'processCountMax' => WorkerTransportHistory::SEQUENCE_PROCESSES,
        ];
        $maximaByTransport = [];
        foreach ($this->streamRowsInWindow('bucketStart', ['transport', 'bucketStart', ...array_keys($sequenceNamesByProperty)], $from, $to, $transport) as $row) {
            $stepStart = $this->getStepStart($row['bucketStart'], $stepSeconds);
            foreach ($sequenceNamesByProperty as $property => $sequenceName) {
                $maximaByTransport[(string)$row['transport']][$sequenceName] ??= [];
                if ($row[$property] === null) {
                    // the queue could not be counted in this minute
                    continue;
                }
                $maximaByTransport[(string)$row['transport']][$sequenceName][$stepStart] = max(
                    $maximaByTransport[(string)$row['transport']][$sequenceName][$stepStart] ?? 0,
                    (int)$row[$property]
                );
            }
        }
        $workerTransportHistories = new WorkerTransportHistories();
        foreach ($maximaByTransport as $transportName => $maximaBySequenceName) {
            $workerTransportHistory = $workerTransportHistories->getOrCreateByTransport((string)$transportName);
            foreach ($maximaBySequenceName as $sequenceName => $valuesByStepStart) {
                $workerTransportHistory->addSequence($sequenceName, $from, $to, $stepSeconds, $valuesByStepStart);
            }
        }
        return $workerTransportHistories;
    }

    /**
     * The queue and worker state of all transports together in a window: waiting, inFlight, processes. The samples
     * of a minute are added up over the transports, a step shows its highest minute — what was true at one moment,
     * not the sum of maxima that were reached at different times. A step without a sample has no value (null).
     *
     * @return WorkerTransportHistory The sequences, under an empty transport name
     */
    public function findTotalSampleSequences(DateTime $from, DateTime $to, int $stepSeconds): WorkerTransportHistory
    {
        $sequenceNamesByProperty = [
            'waitingMax' => WorkerTransportHistory::SEQUENCE_WAITING,
            'inFlightMax' => WorkerTransportHistory::SEQUENCE_IN_FLIGHT,
            'processCountMax' => WorkerTransportHistory::SEQUENCE_PROCESSES,
        ];
        $sumsByMinute = array_fill_keys(array_values($sequenceNamesByProperty), []);
        foreach ($this->streamRowsInWindow('bucketStart', ['bucketStart', ...array_keys($sequenceNamesByProperty)], $from, $to) as $row) {
            $minuteStart = $this->getStepStart($row['bucketStart'], 60);
            foreach ($sequenceNamesByProperty as $property => $sequenceName) {
                if ($row[$property] !== null) {
                    $sumsByMinute[$sequenceName][$minuteStart] = ($sumsByMinute[$sequenceName][$minuteStart] ?? 0) + (int)$row[$property];
                }
            }
        }
        $workerTransportHistory = new WorkerTransportHistory();
        foreach ($sumsByMinute as $sequenceName => $sumByMinute) {
            $maximaByStepStart = [];
            foreach ($sumByMinute as $minuteStart => $sum) {
                $stepStart = intdiv($minuteStart, $stepSeconds) * $stepSeconds;
                $maximaByStepStart[$stepStart] = max($maximaByStepStart[$stepStart] ?? 0, $sum);
            }
            $workerTransportHistory->addSequence($sequenceName, $from, $to, $stepSeconds, $maximaByStepStart);
        }
        return $workerTransportHistory;
    }

    /**
     * Adds the worker seconds of fixed windows back from $now to the throughputs: per sampled minute the highest
     * number of live processes × 60. A transport that was sampled and has no throughput yet gets one.
     *
     * @param int[] $windowSeconds
     */
    public function addWorkerSecondsToThroughputs(
        WorkerTransportThroughputs $workerTransportThroughputs,
        DateTime $now,
        array $windowSeconds = WorkerThroughputWindows::WINDOW_SECONDS
    ): void {
        foreach ($this->sumRowsPerTransportAndWindow('bucketStart', ['processCountMax'], $now, $windowSeconds) as $row) {
            $workerThroughputWindows = $workerTransportThroughputs->getOrCreateByTransport((string)$row['transport'], $windowSeconds)->throughputWindows;
            foreach ($workerThroughputWindows->getElements() as $workerThroughputWindow) {
                $workerThroughputWindow->workerSeconds = (int)$row['processCountMax_' . $workerThroughputWindow->windowSeconds] * 60;
            }
        }
    }
}
