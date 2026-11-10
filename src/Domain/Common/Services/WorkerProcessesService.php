<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistories;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistory;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerProcess;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerProcesses;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * @method WorkerProcess find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method WorkerProcesses findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBWorkerProcess getEntityRepoClassInstance()
 * @method DBWorkerProcesses getEntitySetRepoClassInstance()
 */
class WorkerProcessesService extends WorkerMonitoringEntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = WorkerProcess::class;

    /** Internal reads set their own limit: the set's default `top` is for API lists */
    public const int MAX_ROWS_PER_QUERY = 200000;

    /**
     * Writes the process' own row: an upsert on its process key that carries only the properties the process set —
     * a restart request another process stored stays untouched.
     */
    public function writeWorkerProcess(WorkerProcess $workerProcess): void
    {
        $workerProcesses = new WorkerProcesses();
        $workerProcesses->add($workerProcess);
        $this->batchUpdate($workerProcesses);
    }

    /** @return WorkerProcesses Every process that has not reported a stop (running, busy, silent or not yet noticed as lost) */
    public function findWorkerProcessesNotStopped(): WorkerProcesses
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder = $repoClass::createQueryBuilder(true)
            ->setMaxResults(self::MAX_ROWS_PER_QUERY)
            ->andWhere("{$alias}.state <> :stopped")->setParameter('stopped', WorkerProcess::STATE_STOPPED)
            ->addOrderBy("{$alias}.startedAt", 'ASC');
        return $repoClass->find($queryBuilder, false) ?? new WorkerProcesses();
    }

    /**
     * The processes that are alive now: not stopped, with a recent heartbeat or a message in work.
     *
     * @param string|null $transport Only processes that consume this transport
     */
    public function findLiveWorkerProcesses(DateTime $now, int $lostAfterSeconds, int $busySilentSeconds, ?string $transport = null): WorkerProcesses
    {
        $liveWorkerProcesses = new WorkerProcesses();
        foreach ($this->findWorkerProcessesNotStopped()->getElements() as $workerProcess) {
            if ($transport !== null && !$workerProcess->consumesTransport($transport)) {
                continue;
            }
            if ($workerProcess->isLive($now, $lostAfterSeconds, $busySilentSeconds)) {
                $liveWorkerProcesses->add($workerProcess);
            }
        }
        return $liveWorkerProcesses;
    }

    /**
     * Asks the live processes to restart: each stops after its current message, at the latest at its next heartbeat;
     * whatever runs the workers (process supervisor, orchestrator) starts them again. Works across hosts.
     *
     * @param string|null $transport Only the processes of this transport; null = all
     * @return WorkerProcesses The processes that were asked
     */
    public function requestRestartOfWorkerProcesses(DateTime $now, int $lostAfterSeconds, int $busySilentSeconds, ?string $transport = null): WorkerProcesses
    {
        $liveWorkerProcesses = $this->findLiveWorkerProcesses($now, $lostAfterSeconds, $busySilentSeconds, $transport);
        $processIds = $liveWorkerProcesses->getEntityIds();
        if ($processIds === []) {
            return $liveWorkerProcesses;
        }
        $repoClass = $this->getEntityRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $repoClass::createQueryBuilder()
            ->update($repoClass::BASE_ORM_MODEL, $alias)
            ->set("{$alias}.restartRequestedAt", ':now')->setParameter('now', $now)
            ->where("{$alias}.id IN (:processIds)")->setParameter('processIds', $processIds)
            ->getQuery()->execute();
        foreach ($liveWorkerProcesses->getElements() as $workerProcess) {
            $workerProcess->restartRequestedAt = $now;
        }
        return $liveWorkerProcesses;
    }

    /**
     * Closes processes that vanished without a stop: idle ones without a heartbeat since $idleLastSeenBefore, and
     * ones whose current message started before $busySinceBefore. They become STOPPED with stop reason LOST.
     *
     * @return int Closed processes
     */
    public function closeLostWorkerProcesses(DateTime $now, DateTime $idleLastSeenBefore, DateTime $busySinceBefore): int
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        return (int)$repoClass::createQueryBuilder()
            ->update($repoClass::BASE_ORM_MODEL, $alias)
            ->set("{$alias}.state", ':stopped')->setParameter('stopped', WorkerProcess::STATE_STOPPED)
            ->set("{$alias}.stopReason", ':lost')->setParameter('lost', WorkerProcess::STOP_REASON_LOST)
            ->set("{$alias}.stoppedAt", ':now')->setParameter('now', $now)
            ->set("{$alias}.currentMessageClass", 'NULL')
            ->where("{$alias}.state <> :stopped")
            ->andWhere(
                "({$alias}.currentMessageClass IS NULL AND {$alias}.lastSeenAt < :idleLastSeenBefore)"
                . " OR ({$alias}.currentMessageClass IS NOT NULL AND {$alias}.currentMessageSince < :busySinceBefore)"
            )
            ->setParameter('idleLastSeenBefore', $idleLastSeenBefore)
            ->setParameter('busySinceBefore', $busySinceBefore)
            ->getQuery()->execute();
    }

    /** @return int Deleted processes that stopped before $before */
    public function deleteStoppedWorkerProcessesOlderThan(DateTime $before): int
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        return (int)$repoClass::createQueryBuilder()
            ->delete($repoClass::BASE_ORM_MODEL, $alias)
            ->where("{$alias}.state = :stopped")->setParameter('stopped', WorkerProcess::STATE_STOPPED)
            ->andWhere("{$alias}.stoppedAt < :before")->setParameter('before', $before)
            ->getQuery()->execute();
    }

    /**
     * The processes that started in a time window, oldest first; without a transport those of all transports.
     */
    public function findWorkerProcessesStartedInWindow(DateTime $from, DateTime $to, ?string $transport = null): WorkerProcesses
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder = $repoClass::createQueryBuilder(true)
            ->setMaxResults(self::MAX_ROWS_PER_QUERY)
            ->andWhere("{$alias}.startedAt >= :from")->setParameter('from', $from)
            ->andWhere("{$alias}.startedAt <= :to")->setParameter('to', $to)
            ->addOrderBy("{$alias}.startedAt", 'ASC');
        $workerProcesses = $repoClass->find($queryBuilder, false) ?? new WorkerProcesses();
        if ($transport === null) {
            return $workerProcesses;
        }
        $workerProcessesOfTransport = new WorkerProcesses();
        foreach ($workerProcesses->getElements() as $workerProcess) {
            if ($workerProcess->consumesTransport($transport)) {
                $workerProcessesOfTransport->add($workerProcess);
            }
        }
        return $workerProcessesOfTransport;
    }

    /**
     * How many worker processes started per step of a window, as the sequence processStarts per transport. A process
     * that consumes several transports counts for each of them.
     *
     * @param string|null $transport Only this transport
     */
    public function findProcessStartSequencesByTransport(DateTime $from, DateTime $to, int $stepSeconds, ?string $transport = null): WorkerTransportHistories
    {
        $startsByTransport = [];
        foreach ($this->streamRowsInWindow('startedAt', ['transport', 'startedAt'], $from, $to) as $row) {
            $stepStart = $this->getStepStart($row['startedAt'], $stepSeconds);
            foreach (array_filter(explode(WorkerProcess::TRANSPORT_SEPARATOR, (string)$row['transport'])) as $transportName) {
                if ($transport !== null && $transportName !== $transport) {
                    continue;
                }
                $startsByTransport[$transportName][$stepStart] = ($startsByTransport[$transportName][$stepStart] ?? 0) + 1;
            }
        }
        $workerTransportHistories = new WorkerTransportHistories();
        foreach ($startsByTransport as $transportName => $startsByStepStart) {
            $workerTransportHistories->getOrCreateByTransport((string)$transportName)
                ->addSequence(WorkerTransportHistory::SEQUENCE_PROCESS_STARTS, $from, $to, $stepSeconds, $startsByStepStart);
        }
        return $workerTransportHistories;
    }

    /**
     * The processes that consume a transport, as the set's QueryOptions say (filters, order, page). The transport is
     * matched as a member of the process' transport list.
     */
    public function findWorkerProcessesOfTransport(string $transport): WorkerProcesses
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $separator = WorkerProcess::TRANSPORT_SEPARATOR;
        $escapedTransport = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $transport);
        $queryBuilder = $repoClass::createQueryBuilder(true)
            ->andWhere(
                "({$alias}.transport = :transport OR {$alias}.transport LIKE :transportFirst ESCAPE '!'"
                . " OR {$alias}.transport LIKE :transportLast ESCAPE '!' OR {$alias}.transport LIKE :transportInner ESCAPE '!')"
            )
            ->setParameter('transport', $transport)
            ->setParameter('transportFirst', $escapedTransport . $separator . '%')
            ->setParameter('transportLast', '%' . $separator . $escapedTransport)
            ->setParameter('transportInner', '%' . $separator . $escapedTransport . $separator . '%');
        return $repoClass->find($queryBuilder, false) ?? new WorkerProcesses();
    }
}
