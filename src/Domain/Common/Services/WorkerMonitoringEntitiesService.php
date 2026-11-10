<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DateTimeInterface;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Doctrine\ORM\AbstractQuery;

/**
 * Base of the worker monitoring's entity services: reads the rows of a time window as plain column values, one at a
 * time, so a window of days is aggregated into sequences without building an entity per minute and transport. The
 * entity's read rights apply as in a find.
 */
abstract class WorkerMonitoringEntitiesService extends EntitiesService
{
    /**
     * @param string $timeProperty The property the window and the order apply to
     * @param string[] $properties The properties to read; each row is keyed by them
     * @param string|null $transport Only rows whose `transport` equals it
     * @return iterable<int, array<string, mixed>> The rows, oldest first (an internal helper: aggregated by the caller)
     */
    protected function streamRowsInWindow(string $timeProperty, array $properties, DateTime $from, DateTime $to, ?string $transport = null): iterable
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder = $repoClass::createQueryBuilder(true)
            ->select(implode(', ', array_map(static fn(string $property): string => "{$alias}.{$property} AS {$property}", $properties)))
            ->andWhere("{$alias}.{$timeProperty} >= :from")->setParameter('from', $from)
            ->andWhere("{$alias}.{$timeProperty} <= :to")->setParameter('to', $to)
            ->addOrderBy("{$alias}.{$timeProperty}", 'ASC');
        if ($transport !== null) {
            $queryBuilder->andWhere("{$alias}.transport = :transport")->setParameter('transport', $transport);
        }
        $repoClass::applyReadRightsQuery($queryBuilder);
        return $queryBuilder->getQuery()->toIterable([], AbstractQuery::HYDRATE_SCALAR);
    }

    /** The start of the step holding a stored time (a date-time object or the database's `Y-m-d H:i:s` text, UTC) */
    protected function getStepStart(mixed $storedTime, int $stepSeconds): int
    {
        $timestamp = $storedTime instanceof DateTimeInterface
            ? $storedTime->getTimestamp()
            : (int)strtotime((string)$storedTime . ' UTC');
        return DateTimeValueSequence::alignToStep($timestamp, $stepSeconds);
    }

    /**
     * Sums of the given properties per transport and window, each window reaching back from $now and holding the
     * whole minute buckets that start in it. One aggregate statement over the longest window.
     *
     * @param string[] $properties The properties to add up
     * @param int[] $windowSeconds
     * @return iterable<int, array<string, mixed>> One row per transport, keyed `transport` and `<property>_<seconds>`
     *     (an internal helper: aggregated by the caller)
     */
    protected function sumRowsPerTransportAndWindow(string $timeProperty, array $properties, DateTime $now, array $windowSeconds): iterable
    {
        if ($windowSeconds === [] || $properties === []) {
            return [];
        }
        $repoClass = $this->getEntityRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder = $repoClass::createQueryBuilder(true)->select("{$alias}.transport AS transport");
        foreach ($windowSeconds as $seconds) {
            $seconds = (int)$seconds;
            $windowStart = DateTime::fromTimestamp(DateTimeValueSequence::alignToStep($now->getTimestamp() - $seconds, 60));
            $queryBuilder->setParameter('from' . $seconds, $windowStart);
            foreach ($properties as $property) {
                $queryBuilder->addSelect(
                    "SUM(CASE WHEN {$alias}.{$timeProperty} >= :from{$seconds} THEN {$alias}.{$property} ELSE 0 END) AS {$property}_{$seconds}"
                );
            }
        }
        $longestWindowStart = DateTime::fromTimestamp(DateTimeValueSequence::alignToStep($now->getTimestamp() - max($windowSeconds), 60));
        $queryBuilder
            ->andWhere("{$alias}.{$timeProperty} >= :from")->setParameter('from', $longestWindowStart)
            ->andWhere("{$alias}.{$timeProperty} <= :to")->setParameter('to', $now)
            ->groupBy("{$alias}.transport");
        $repoClass::applyReadRightsQuery($queryBuilder);
        return $queryBuilder->getQuery()->getScalarResult();
    }
}
