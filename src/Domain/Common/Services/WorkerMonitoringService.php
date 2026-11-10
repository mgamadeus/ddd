<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRun;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRuns;
use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Domain\Common\Entities\Workers\WorkerQueueCount;
use DDD\Domain\Common\Entities\Workers\WorkerQueueCounts;
use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Domain\Common\Entities\Workers\WorkerStatBucket;
use DDD\Domain\Common\Entities\Workers\WorkerStatBuckets;
use DDD\Domain\Common\Entities\Workers\WorkerTransportSample;
use DDD\Domain\Common\Entities\Workers\WorkerTransportSamples;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Throwable;

/**
 * Measures what the Messenger workers of this process do and writes it to the database in batches — no handler is
 * changed for it:
 *
 * - per consumed message: transport, class, wait, duration and outcome go into an in-memory statistics bucket
 *   ({@see WorkerStatBucket}, per transport + class + minute) and, depending on the transport's message log mode, into
 *   a {@see WorkerMessageRun};
 * - the worker process registers itself, reports a heartbeat and closes itself with a stop reason
 *   ({@see WorkerProcess});
 * - with every heartbeat the queue and worker state of the process' transports is sampled
 *   ({@see WorkerTransportSample}); {@see self::sampleTransports()} does the same for any transports (the sampler
 *   command: also for a transport without a worker).
 *
 * Nothing is written per message: buckets and runs are flushed at most every `flushSeconds`, on the worker's tick
 * between two messages, and when it stops. One exception, throttled to once a second per process: the message a
 * process starts is written with its start, so a long job is visible while it runs.
 *
 * Off unless `Common.Workers` `enabled` is set (the tables must exist). Fail-soft: a failed write never touches a
 * job; the monitoring of the process pauses for {@see self::FAILURE_BACKOFF_SECONDS} and says so once in the log.
 *
 * Called by {@see \DDD\Symfony\Messenger\Middleware\WorkerMonitoringMiddleware},
 * {@see \DDD\Symfony\EventListeners\WorkerMonitoringSubscriber} and
 * {@see \DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler} (caught errors).
 */
class WorkerMonitoringService extends Service
{
    public const string CONSUME_COMMAND_NAME = 'messenger:consume';
    public const string UNKNOWN_TRANSPORT = 'unknown';
    public const int FAILURE_BACKOFF_SECONDS = 300;
    public const int PRUNE_INTERVAL_SECONDS = 600;
    /** The message a process starts is written at most this often */
    public const int CURRENT_MESSAGE_WRITE_INTERVAL_SECONDS = 1;
    public const int ERROR_MESSAGE_MAX_LENGTH = 500;
    public const int CLASS_NAME_MAX_LENGTH = 255;
    /** Pending message runs above which a flush is due whatever the interval */
    public const int MAX_PENDING_MESSAGE_RUNS = 2000;
    /** An idle process without a heartbeat is closed as lost after this many heartbeat intervals */
    public const int CLOSE_LOST_AFTER_HEARTBEATS = 12;

    protected ?WorkersSettings $workersSettings = null;

    /** @var bool A Messenger worker runs in this process (false: a message run from the command line) */
    protected bool $workerIsRegistered = false;
    protected ?string $processKey = null;
    protected ?string $hostName = null;
    protected ?int $processId = null;
    protected ?float $workerStartedAt = null;
    /** @var string[] */
    protected array $transportNames = [];

    protected ?int $messageLimit = null;
    protected ?int $timeLimitSeconds = null;
    protected ?int $memoryLimitBytes = null;
    protected ?int $failureLimit = null;
    protected bool $signalWasReceived = false;
    protected bool $restartWasRequested = false;

    protected int $handledCount = 0;
    protected int $failedCount = 0;
    protected int $memoryPeakBytes = 0;
    protected ?float $firstMessageAt = null;

    protected ?WorkerMessageRun $currentWorkerMessageRun = null;
    protected float $currentMessageStartedAt = 0.0;
    protected ?Throwable $currentCaughtThrowable = null;
    /** @var WorkerMessageRun|null The run of the message that just finished: the failed event completes it */
    protected ?WorkerMessageRun $lastWorkerMessageRun = null;

    protected ?WorkerStatBuckets $pendingWorkerStatBuckets = null;
    protected ?WorkerMessageRuns $pendingWorkerMessageRuns = null;

    protected float $lastFlushAt = 0.0;
    protected float $lastHeartbeatAt = 0.0;
    protected float $lastCurrentMessageWriteAt = 0.0;
    protected float $lastPruneAt = 0.0;
    protected float $pausedUntil = 0.0;

    public function getSettings(): WorkersSettings
    {
        return $this->workersSettings ??= WorkersSettings::fromEnvironment();
    }

    /** @return bool The monitoring writes: switched on and not paused after a failed write */
    public function isEnabled(): bool
    {
        return $this->getSettings()->enabled && $this->getCurrentTime() >= $this->pausedUntil;
    }

    public function getProcessKey(): ?string
    {
        return $this->processKey;
    }

    // ---------------------------------------------------------------------------------------------------- messages

    /**
     * A consumed message starts.
     */
    public function startMessage(Envelope $envelope): void
    {
        $this->currentWorkerMessageRun = null;
        $this->currentCaughtThrowable = null;
        if (!$this->isEnabled()) {
            return;
        }
        try {
            $now = $this->getCurrentTime();
            $message = $envelope->getMessage();
            /** @var ReceivedStamp|null $receivedStamp */
            $receivedStamp = $envelope->last(ReceivedStamp::class);
            /** @var RedeliveryStamp|null $redeliveryStamp */
            $redeliveryStamp = $envelope->last(RedeliveryStamp::class);

            $workerMessageRun = new WorkerMessageRun();
            $workerMessageRun->transport = $receivedStamp?->getTransportName() ?: self::UNKNOWN_TRANSPORT;
            $workerMessageRun->messageClass = $this->shorten($message::class, self::CLASS_NAME_MAX_LENGTH);
            $workerMessageRun->processKey = $this->workerIsRegistered ? $this->processKey : null;
            $workerMessageRun->startedAt = DateTime::fromTimestamp((int)$now);
            $workerMessageRun->attempt = ($redeliveryStamp?->getRetryCount() ?? 0) + 1;
            $workerMessageRun->dispatchedAt = null;
            $workerMessageRun->dispatchedFromRoute = null;
            $workerMessageRun->dispatchedFromCommand = null;
            $workerMessageRun->waitMs = null;
            $workerMessageRun->willRetry = null;
            $workerMessageRun->errorClass = null;
            $workerMessageRun->errorMessage = null;
            $workerMessageRun->issueGroupHash = null;
            $waitStartedAt = null;
            if ($message instanceof AppMessage && $message->dispatchOrigin !== null) {
                $workerMessageRun->dispatchedAt = $message->dispatchOrigin->dispatchedAt;
                $workerMessageRun->dispatchedFromRoute = $this->shortenOrNull($message->dispatchOrigin->route, self::CLASS_NAME_MAX_LENGTH);
                $workerMessageRun->dispatchedFromCommand = $this->shortenOrNull($message->dispatchOrigin->command, self::CLASS_NAME_MAX_LENGTH);
                $waitStartedAt = $message->dispatchOrigin->dispatchedAt?->getTimestamp();
            }
            if ($redeliveryStamp !== null) {
                // a retry waited since its redelivery, not since the first dispatch
                $waitStartedAt = $redeliveryStamp->getRedeliveredAt()->getTimestamp();
            }
            if ($waitStartedAt !== null) {
                $workerMessageRun->waitMs = max(0, (int)round(($now - $waitStartedAt) * 1000));
            }
            $this->currentWorkerMessageRun = $workerMessageRun;
            $this->currentMessageStartedAt = $now;
            $this->firstMessageAt ??= $now;
            if ($this->workerIsRegistered && $now - $this->lastCurrentMessageWriteAt >= self::CURRENT_MESSAGE_WRITE_INTERVAL_SECONDS) {
                $this->lastCurrentMessageWriteAt = $now;
                $this->runWithoutRightsRestrictions(fn() => $this->writeProcess(WorkerProcess::STATE_RUNNING, $now));
            }
        } catch (Throwable $throwable) {
            $this->pauseAfterFailure($throwable);
        }
    }

    /**
     * The handler of the current message logged an error and goes on (it will return normally): the message counts
     * as failed, kind "caught".
     */
    public function reportCaughtThrowable(Throwable $throwable): void
    {
        if ($this->currentWorkerMessageRun !== null) {
            $this->currentCaughtThrowable ??= $throwable;
        }
    }

    /**
     * The current message is finished. Nothing is written here — a transaction the handler left open may still be
     * open; the next worker tick flushes.
     *
     * @param Throwable|null $thrownThrowable The exception that left the handler; null = it returned
     */
    public function finishMessage(?Throwable $thrownThrowable = null): void
    {
        $workerMessageRun = $this->currentWorkerMessageRun;
        $this->currentWorkerMessageRun = null;
        if ($workerMessageRun === null) {
            return;
        }
        try {
            $now = $this->getCurrentTime();
            $workerMessageRun->durationMs = max(0, (int)round(($now - $this->currentMessageStartedAt) * 1000));
            $errorThrowable = $thrownThrowable !== null ? $this->unwrapThrowable($thrownThrowable) : $this->currentCaughtThrowable;
            $workerMessageRun->outcome = match (true) {
                $thrownThrowable !== null => WorkerMessageRun::OUTCOME_THROWN,
                $errorThrowable !== null => WorkerMessageRun::OUTCOME_CAUGHT,
                default => WorkerMessageRun::OUTCOME_HANDLED,
            };
            if ($errorThrowable !== null) {
                $workerMessageRun->errorClass = $this->shorten($errorThrowable::class, self::CLASS_NAME_MAX_LENGTH);
                $workerMessageRun->errorMessage = $this->shorten($errorThrowable->getMessage(), self::ERROR_MESSAGE_MAX_LENGTH);
                $workerMessageRun->issueGroupHash = $this->getIssueGroupHash($errorThrowable);
            }
            $this->currentCaughtThrowable = null;
            $this->handledCount++;
            if ($workerMessageRun->isFailed()) {
                $this->failedCount++;
            }
            $this->memoryPeakBytes = max($this->memoryPeakBytes, memory_get_usage(true));
            $this->addToPendingWorkerStatBucket($workerMessageRun, $now);
            $messageLogMode = $this->getMessageLogMode((string)$workerMessageRun->transport);
            if ($messageLogMode === WorkersSettings::MESSAGE_LOG_ALL
                || ($messageLogMode === WorkersSettings::MESSAGE_LOG_FAILURES && $workerMessageRun->isFailed())) {
                $this->pendingWorkerMessageRuns ??= new WorkerMessageRuns();
                $this->pendingWorkerMessageRuns->add($workerMessageRun);
            }
            $this->lastWorkerMessageRun = $workerMessageRun;
        } catch (Throwable $throwable) {
            $this->pauseAfterFailure($throwable);
        }
    }

    /**
     * Messenger decided what happens to the message that just threw: retried or failed for good.
     */
    public function reportWillRetryOfLastMessage(bool $willRetry): void
    {
        if ($this->lastWorkerMessageRun !== null && $this->lastWorkerMessageRun->outcome === WorkerMessageRun::OUTCOME_THROWN) {
            $this->lastWorkerMessageRun->willRetry = $willRetry;
        }
    }

    protected function addToPendingWorkerStatBucket(WorkerMessageRun $workerMessageRun, float $now): void
    {
        $this->pendingWorkerStatBuckets ??= new WorkerStatBuckets();
        $candidate = new WorkerStatBucket();
        $candidate->transport = $workerMessageRun->transport;
        $candidate->messageClass = $workerMessageRun->messageClass;
        $candidate->bucketStart = $this->getMinuteStart($now);
        $workerStatBucket = $this->pendingWorkerStatBuckets->getByUniqueKey($candidate->uniqueKey());
        if ($workerStatBucket === null) {
            $workerStatBucket = $candidate;
            $this->pendingWorkerStatBuckets->add($workerStatBucket);
        }
        $workerStatBucket->handledCount++;
        $workerStatBucket->durationTotalMs += $workerMessageRun->durationMs;
        $workerStatBucket->durationMaxMs = max($workerStatBucket->durationMaxMs, $workerMessageRun->durationMs);
        if ($workerMessageRun->attempt > 1) {
            $workerStatBucket->redeliveredCount++;
        }
        if ($workerMessageRun->waitMs !== null) {
            $workerStatBucket->waitCount++;
            $workerStatBucket->waitTotalMs += $workerMessageRun->waitMs;
            $workerStatBucket->waitMaxMs = max($workerStatBucket->waitMaxMs, $workerMessageRun->waitMs);
        }
        if ($workerMessageRun->outcome === WorkerMessageRun::OUTCOME_THROWN) {
            $workerStatBucket->thrownCount++;
        } elseif ($workerMessageRun->outcome === WorkerMessageRun::OUTCOME_CAUGHT) {
            $workerStatBucket->caughtCount++;
        }
        if ($workerMessageRun->isFailed()) {
            $workerStatBucket->lastErrorClass = $workerMessageRun->errorClass;
            $workerStatBucket->lastErrorMessage = $workerMessageRun->errorMessage;
            $workerStatBucket->lastErrorAt = DateTime::fromTimestamp((int)$now);
        }
    }

    // ------------------------------------------------------------------------------------------------------ worker

    /**
     * The limits the consume command of this process runs with: they tell the stop reasons apart.
     */
    public function setConsumeLimits(?int $messageLimit, ?int $timeLimitSeconds, ?int $memoryLimitBytes, ?int $failureLimit): void
    {
        $this->messageLimit = $messageLimit;
        $this->timeLimitSeconds = $timeLimitSeconds;
        $this->memoryLimitBytes = $memoryLimitBytes;
        $this->failureLimit = $failureLimit;
    }

    /**
     * A worker started in this process.
     *
     * @param string[] $transportNames The transports it consumes
     */
    public function registerWorker(array $transportNames): void
    {
        $now = $this->getCurrentTime();
        $this->workerIsRegistered = true;
        $this->transportNames = array_values($transportNames);
        $this->workerStartedAt = $now;
        $this->hostName = $this->getHostName();
        $this->processId = $this->getProcessId();
        $this->processKey = $this->shorten($this->hostName . ':' . $this->processId . ':' . (int)$now, 191);
        $this->handledCount = 0;
        $this->failedCount = 0;
        $this->firstMessageAt = null;
        $this->signalWasReceived = false;
        $this->restartWasRequested = false;
        $this->lastFlushAt = $now;
        if (!$this->isEnabled()) {
            return;
        }
        try {
            $this->runWithoutRightsRestrictions(fn() => $this->writeProcess(WorkerProcess::STATE_RUNNING, $now));
            $this->lastHeartbeatAt = $now;
        } catch (Throwable $throwable) {
            $this->pauseAfterFailure($throwable);
        }
    }

    /** The process received a stop signal */
    public function reportSignal(): void
    {
        $this->signalWasReceived = true;
    }

    /**
     * The worker's tick: between two messages and while idle. Flushes what is due, reports the heartbeat when due.
     *
     * @return bool true = a restart was requested for this process: the caller stops the worker
     */
    public function tick(): bool
    {
        if (!$this->workerIsRegistered || !$this->isEnabled()) {
            return false;
        }
        try {
            $now = $this->getCurrentTime();
            $settings = $this->getSettings();
            if ($now - $this->lastFlushAt >= $settings->flushSeconds
                || ($this->pendingWorkerMessageRuns?->count() ?? 0) >= self::MAX_PENDING_MESSAGE_RUNS) {
                $this->flush();
            }
            if ($now - $this->lastHeartbeatAt >= $settings->heartbeatSeconds) {
                $this->lastHeartbeatAt = $now;
                $this->runWithoutRightsRestrictions(function () use ($now): void {
                    $this->writeProcess(WorkerProcess::STATE_RUNNING, $now);
                    $workerProcessesNotStopped = $this->getWorkerProcessesService()->findWorkerProcessesNotStopped();
                    $ownWorkerProcess = $this->processKey !== null
                        ? $workerProcessesNotStopped->getByUniqueKey(WorkerProcess::uniqueKeyStatic($this->processKey))
                        : null;
                    if ($ownWorkerProcess !== null && isset($ownWorkerProcess->restartRequestedAt)) {
                        $this->restartWasRequested = true;
                    }
                    $this->writeSamples($this->transportNames, $workerProcessesNotStopped, $now, false);
                });
            }
            return $this->restartWasRequested;
        } catch (Throwable $throwable) {
            $this->pauseAfterFailure($throwable);
            return false;
        }
    }

    /**
     * The worker of this process stopped: last flush, the process row is closed with its stop reason.
     */
    public function closeWorker(): void
    {
        if (!$this->workerIsRegistered) {
            return;
        }
        $this->workerIsRegistered = false;
        if (!$this->isEnabled()) {
            return;
        }
        try {
            $this->flush();
            $now = $this->getCurrentTime();
            $this->runWithoutRightsRestrictions(
                fn() => $this->writeProcess(WorkerProcess::STATE_STOPPED, $now, $this->getStopReason($now))
            );
        } catch (Throwable $throwable) {
            $this->pauseAfterFailure($throwable);
        }
    }

    /**
     * Why the worker stops, as far as it can be told: Messenger's stop listeners all call the same stop without a
     * reason, so the reason is inferred — the framework's own restart request, a received signal, then the consume
     * command's limits against what the process did.
     */
    public function getStopReason(float $now): string
    {
        if ($this->restartWasRequested) {
            return WorkerProcess::STOP_REASON_RESTART_REQUESTED;
        }
        if ($this->signalWasReceived) {
            return WorkerProcess::STOP_REASON_SIGNAL;
        }
        if ($this->memoryLimitBytes !== null && memory_get_usage(true) >= $this->memoryLimitBytes) {
            return WorkerProcess::STOP_REASON_MEMORY_LIMIT;
        }
        if ($this->messageLimit !== null && $this->handledCount >= $this->messageLimit) {
            return WorkerProcess::STOP_REASON_MESSAGE_LIMIT;
        }
        if ($this->timeLimitSeconds !== null && $this->workerStartedAt !== null
            && $now - $this->workerStartedAt >= $this->timeLimitSeconds) {
            return WorkerProcess::STOP_REASON_TIME_LIMIT;
        }
        if ($this->failureLimit !== null && $this->failedCount >= $this->failureLimit) {
            return WorkerProcess::STOP_REASON_FAILURE_LIMIT;
        }
        return WorkerProcess::STOP_REASON_OTHER;
    }

    /** Writes this process' row. Properties another process owns (the restart request) are left uninitialised */
    protected function writeProcess(string $state, float $now, ?string $stopReason = null): void
    {
        if ($this->processKey === null) {
            return;
        }
        $this->memoryPeakBytes = max($this->memoryPeakBytes, memory_get_usage(true));
        $workerProcess = new WorkerProcess();
        $workerProcess->processKey = $this->processKey;
        $workerProcess->hostName = $this->shortenOrNull($this->hostName, 128);
        $workerProcess->processId = $this->processId;
        $workerProcess->transport = $this->shorten(implode(WorkerProcess::TRANSPORT_SEPARATOR, $this->transportNames), 255);
        $workerProcess->startedAt = DateTime::fromTimestamp((int)($this->workerStartedAt ?? $now));
        $workerProcess->firstMessageAt = $this->firstMessageAt !== null ? DateTime::fromTimestamp((int)$this->firstMessageAt) : null;
        $workerProcess->lastSeenAt = DateTime::fromTimestamp((int)$now);
        $workerProcess->state = $state;
        $workerProcess->handledCount = $this->handledCount;
        $workerProcess->failedCount = $this->failedCount;
        $workerProcess->memoryBytes = memory_get_usage(true);
        $workerProcess->memoryPeakBytes = $this->memoryPeakBytes;
        $isStopped = $state === WorkerProcess::STATE_STOPPED;
        $workerProcess->currentMessageClass = $isStopped ? null : $this->currentWorkerMessageRun?->messageClass;
        $workerProcess->currentMessageSince = !$isStopped && $this->currentWorkerMessageRun !== null
            ? DateTime::fromTimestamp((int)$this->currentMessageStartedAt)
            : null;
        $workerProcess->stoppedAt = $isStopped ? DateTime::fromTimestamp((int)$now) : null;
        $workerProcess->stopReason = $stopReason;
        $this->getWorkerProcessesService()->writeWorkerProcess($workerProcess);
    }

    // ------------------------------------------------------------------------------------------------------- flush

    /**
     * Writes the collected buckets and message runs — one multi-row statement each — and prunes when due.
     */
    public function flush(): void
    {
        $now = $this->getCurrentTime();
        $this->lastFlushAt = $now;
        $pendingWorkerStatBuckets = $this->pendingWorkerStatBuckets;
        $pendingWorkerMessageRuns = $this->pendingWorkerMessageRuns;
        // dropped also when the write fails: a process never accumulates what it cannot store
        $this->pendingWorkerStatBuckets = null;
        $this->pendingWorkerMessageRuns = null;
        $this->lastWorkerMessageRun = null;
        if (!$this->isEnabled()) {
            return;
        }
        try {
            $this->runWithoutRightsRestrictions(function () use ($pendingWorkerStatBuckets, $pendingWorkerMessageRuns, $now): void {
                if ($pendingWorkerStatBuckets !== null) {
                    $this->getWorkerStatBucketsService()->addWorkerStatBuckets($pendingWorkerStatBuckets);
                }
                if ($pendingWorkerMessageRuns !== null) {
                    $this->getWorkerMessageRunsService()->addWorkerMessageRuns($pendingWorkerMessageRuns);
                }
                if ($now - $this->lastPruneAt >= self::PRUNE_INTERVAL_SECONDS) {
                    // not right at the start: the workers of a deployment start together
                    $pruneIsDue = $this->lastPruneAt > 0.0;
                    $this->lastPruneAt = $now - ($pruneIsDue ? 0 : random_int(0, self::PRUNE_INTERVAL_SECONDS - 1));
                    if ($pruneIsDue) {
                        $this->prune();
                    }
                }
            });
        } catch (Throwable $throwable) {
            $this->pauseAfterFailure($throwable);
        }
    }

    /**
     * A console command ends: what a message run from the command line collected is written. A worker flushes on its
     * own ticks and when it stops.
     */
    public function flushAfterCommandLineRun(): void
    {
        if ($this->workerIsRegistered || ($this->pendingWorkerStatBuckets === null && $this->pendingWorkerMessageRuns === null)) {
            return;
        }
        $this->flush();
    }

    /**
     * Deletes what is older than the retention and closes processes that vanished without a stop. Runs inside the
     * flush every {@see self::PRUNE_INTERVAL_SECONDS} and with the sampler command, so every run deletes little.
     * The caller switches rights restrictions off.
     */
    public function prune(): void
    {
        $settings = $this->getSettings();
        $now = (int)$this->getCurrentTime();
        $daysAgo = static fn(int $days): DateTime => DateTime::fromTimestamp($now - $days * 86400);
        $this->getWorkerStatBucketsService()->deleteWorkerStatBucketsOlderThan($daysAgo($settings->statRetentionDays));
        $this->getWorkerTransportSamplesService()->deleteWorkerTransportSamplesOlderThan($daysAgo($settings->statRetentionDays));
        $this->getWorkerMessageRunsService()->deleteWorkerMessageRunsOlderThan(
            $daysAgo($settings->handledRunRetentionDays),
            $daysAgo($settings->failedRunRetentionDays)
        );
        $workerProcessesService = $this->getWorkerProcessesService();
        $workerProcessesService->closeLostWorkerProcesses(
            DateTime::fromTimestamp($now),
            DateTime::fromTimestamp($now - $settings->heartbeatSeconds * self::CLOSE_LOST_AFTER_HEARTBEATS),
            DateTime::fromTimestamp($now - 2 * $settings->busySilentSeconds)
        );
        $workerProcessesService->deleteStoppedWorkerProcessesOlderThan($daysAgo($settings->processRetentionDays));
    }

    // ----------------------------------------------------------------------------------------------------- samples

    /**
     * Samples the queue and worker state of the given transports now and merges it into the minute's samples.
     * Called by the sampler command for every transport — also one without a worker, so the history has no holes.
     *
     * @param string[] $transports
     * @param bool $sampledByCommand The sampler command writes (its time is kept apart: the check looks for it)
     * @return WorkerQueueCounts What the transports answered
     */
    public function sampleTransports(array $transports, bool $sampledByCommand = true): WorkerQueueCounts
    {
        $workerQueueCounts = new WorkerQueueCounts();
        $this->runWithoutRightsRestrictions(function () use ($transports, $sampledByCommand, &$workerQueueCounts): void {
            $workerQueueCounts = $this->writeSamples(
                $transports,
                $this->getWorkerProcessesService()->findWorkerProcessesNotStopped(),
                $this->getCurrentTime(),
                $sampledByCommand
            );
        });
        return $workerQueueCounts;
    }

    /**
     * The sampler command's run: samples every transport a worker has to consume (also those of the worker list the
     * Messenger configuration knows) and prunes.
     *
     * @return WorkerQueueCounts What the transports answered
     */
    public function sampleAllTransportsAndPrune(): WorkerQueueCounts
    {
        $workerQueueCounts = $this->sampleTransports($this->getTransportNamesToSample(), true);
        $this->runWithoutRightsRestrictions(fn() => $this->prune());
        return $workerQueueCounts;
    }

    /** @return string[] Every transport a worker has to consume */
    public function getTransportNamesToSample(): array
    {
        /** @var WorkerTransportsService $workerTransportsService */
        $workerTransportsService = DDDService::instance()->getService(WorkerTransportsService::class);
        return $workerTransportsService->getConsumedTransportNames();
    }

    /**
     * @param string[] $transports
     * @param WorkerProcesses $workerProcessesNotStopped Every process that has not reported a stop
     */
    protected function writeSamples(array $transports, WorkerProcesses $workerProcessesNotStopped, float $now, bool $sampledByCommand): WorkerQueueCounts
    {
        if ($transports === []) {
            return new WorkerQueueCounts();
        }
        $settings = $this->getSettings();
        $nowDateTime = DateTime::fromTimestamp((int)$now);
        $workerQueueCounts = $this->getWorkerQueuesService()->countWaitingMessages($transports);
        $workerTransportSamples = new WorkerTransportSamples();
        foreach ($transports as $transport) {
            $processCount = 0;
            $inFlight = 0;
            foreach ($workerProcessesNotStopped->getElements() as $workerProcess) {
                if (!$workerProcess->consumesTransport($transport)
                    || !$workerProcess->isLive($nowDateTime, $settings->getLostAfterSeconds(), $settings->busySilentSeconds)) {
                    continue;
                }
                $processCount++;
                if (($workerProcess->currentMessageClass ?? null) !== null) {
                    $inFlight++;
                }
            }
            $workerQueueCount = $workerQueueCounts->getByTransport($transport);
            $waiting = $workerQueueCount?->state === WorkerQueueCount::STATE_COUNTED ? $workerQueueCount->waiting : null;
            $workerTransportSample = new WorkerTransportSample();
            $workerTransportSample->transport = $transport;
            $workerTransportSample->bucketStart = $this->getMinuteStart($now);
            $workerTransportSample->waitingMax = $waiting;
            $workerTransportSample->waitingLast = $waiting;
            $workerTransportSample->inFlightMax = $inFlight;
            $workerTransportSample->inFlightLast = $inFlight;
            $workerTransportSample->processCountMax = $processCount;
            $workerTransportSample->processCountLast = $processCount;
            $workerTransportSample->lastSampledAt = $nowDateTime;
            if ($sampledByCommand) {
                $workerTransportSample->lastSampledByCommandAt = $nowDateTime;
            }
            $workerTransportSamples->add($workerTransportSample);
        }
        $this->getWorkerTransportSamplesService()->mergeWorkerTransportSamples($workerTransportSamples);
        return $workerQueueCounts;
    }

    // ------------------------------------------------------------------------------------------------------- seams

    /** @return float Unix time with microseconds; the method a test overrides */
    protected function getCurrentTime(): float
    {
        return microtime(true);
    }

    protected function getHostName(): string
    {
        return (string)(gethostname() ?: 'unknown');
    }

    protected function getProcessId(): int
    {
        return (int)getmypid();
    }

    protected function getMinuteStart(float $time): DateTime
    {
        return DateTime::fromTimestamp(intdiv((int)$time, 60) * 60);
    }

    protected function getMessageLogMode(string $transport): string
    {
        /** @var WorkerDefinitionsService $workerDefinitionsService */
        $workerDefinitionsService = DDDService::instance()->getService(WorkerDefinitionsService::class);
        return $workerDefinitionsService->getMessageLogModeForTransport($transport);
    }

    protected function getIssueGroupHash(Throwable $throwable): ?string
    {
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            return $issuesLogService->getGroupHashForThrowable($throwable);
        } catch (Throwable) {
            return null;
        }
    }

    protected function getWorkerStatBucketsService(): WorkerStatBucketsService
    {
        return WorkerStatBuckets::getService();
    }

    protected function getWorkerMessageRunsService(): WorkerMessageRunsService
    {
        return WorkerMessageRuns::getService();
    }

    protected function getWorkerProcessesService(): WorkerProcessesService
    {
        return WorkerProcesses::getService();
    }

    protected function getWorkerTransportSamplesService(): WorkerTransportSamplesService
    {
        return WorkerTransportSamples::getService();
    }

    protected function getWorkerQueuesService(): WorkerQueuesService
    {
        return DDDService::instance()->getService(WorkerQueuesService::class);
    }

    /** The monitoring reads and writes its own tables whatever account the job runs under */
    protected function runWithoutRightsRestrictions(callable $databaseWork): void
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $databaseWork();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** A write failed: the monitoring of this process pauses, and says so once per pause */
    protected function pauseAfterFailure(Throwable $throwable): void
    {
        $this->pausedUntil = $this->getCurrentTime() + self::FAILURE_BACKOFF_SECONDS;
        $this->pendingWorkerStatBuckets = null;
        $this->pendingWorkerMessageRuns = null;
        $this->currentWorkerMessageRun = null;
        try {
            DDDService::instance()->getLogger()->warning(sprintf(
                'Worker monitoring paused for %d s after a failed write: %s',
                self::FAILURE_BACKOFF_SECONDS,
                $throwable->getMessage()
            ));
        } catch (Throwable) {
            error_log('Worker monitoring paused after a failed write: ' . $throwable->getMessage());
        }
    }

    /** The exception a handler threw, out of Messenger's wrapper */
    protected function unwrapThrowable(Throwable $throwable): Throwable
    {
        if ($throwable instanceof WrappedExceptionsInterface) {
            $wrappedThrowables = $throwable->getWrappedExceptions();
            $firstWrappedThrowable = reset($wrappedThrowables);
            if ($firstWrappedThrowable instanceof Throwable) {
                return $firstWrappedThrowable;
            }
        }
        return $throwable;
    }

    protected function shorten(string $text, int $maxLength): string
    {
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength) : $text;
    }

    protected function shortenOrNull(?string $text, int $maxLength): ?string
    {
        return $text === null ? null : $this->shorten($text, $maxLength);
    }
}
