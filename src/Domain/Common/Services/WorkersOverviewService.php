<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequences;
use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Domain\Common\Entities\Charts\UtcTimeInterval;
use DDD\Domain\Common\Entities\Workers\WorkerDefinitions;
use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Domain\Common\Entities\Workers\WorkerQueueCount;
use DDD\Domain\Common\Entities\Workers\WorkerQueueCounts;
use DDD\Domain\Common\Entities\Workers\WorkersHistory;
use DDD\Domain\Common\Entities\Workers\WorkersOverview;
use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Domain\Common\Entities\Workers\WorkerStatBuckets;
use DDD\Domain\Common\Entities\Workers\WorkerTransportDetail;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistories;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistory;
use DDD\Domain\Common\Entities\Workers\WorkerTransportOverview;
use DDD\Domain\Common\Entities\Workers\WorkerTransportSample;
use DDD\Domain\Common\Entities\Workers\WorkerTransportThroughputs;
use DDD\Domain\Common\Entities\Workers\WorkerTransportSamples;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * What an admin screen shows about the workers, read from the worker list, the Messenger configuration and the
 * monitoring's tables:
 *
 * - {@see self::getWorkersOverview()}: every transport with definition, queue, processes, state and the last hour,
 *   and the live processes;
 * - {@see self::getWorkerTransportDetail()}: one transport in a window, with every sequence, its message classes and
 *   live processes;
 * - {@see self::getWorkersHistory()}: processes, starts, queue and throughput of every transport in a window.
 *
 * Reads under the caller's rights (the monitoring's tables are readable by admins). With the monitoring switched off
 * the overview still shows the definitions and asks the broker for the queue lengths.
 */
class WorkersOverviewService extends Service
{
    /** The steps a sequence can have, in seconds */
    public const array ALLOWED_STEP_SECONDS = [60, 300, 900, 3600];
    /** A window is refused when it would need more points per sequence */
    public const int MAX_SEQUENCE_POINTS = 1500;
    public const int OVERVIEW_WINDOW_SECONDS = 3600;
    /** The overview's counts and averages cover this long */
    public const int RECENT_WINDOW_SECONDS = 900;
    public const int OVERVIEW_STEP_SECONDS = 60;
    public const int DEFAULT_WINDOW_SECONDS = 3600;
    /** The sequences of {@see self::getWorkersHistory()}, per transport and for all together */
    public const array HISTORY_SEQUENCE_NAMES = [
        WorkerTransportHistory::SEQUENCE_PROCESSES,
        WorkerTransportHistory::SEQUENCE_PROCESS_STARTS,
        WorkerTransportHistory::SEQUENCE_WAITING,
        WorkerTransportHistory::SEQUENCE_IN_FLIGHT,
        WorkerTransportHistory::SEQUENCE_HANDLED,
        WorkerTransportHistory::SEQUENCE_FAILED,
    ];

    public function getWorkersOverview(): WorkersOverview
    {
        $settings = $this->getSettings();
        $nowTimestamp = $this->getCurrentTimestamp();
        $now = DateTime::fromTimestamp($nowTimestamp);
        $from = DateTime::fromTimestamp($nowTimestamp - self::OVERVIEW_WINDOW_SECONDS);
        $workerDefinitions = $this->getWorkerDefinitions();
        $workerTransportsService = $this->getWorkerTransportsService();
        $consumedTransportNames = $workerTransportsService->getConsumedTransportNames();
        $transportNames = array_values(array_unique([...$consumedTransportNames, ...$workerDefinitions->consumers->getTransportNames()]));

        $workersOverview = new WorkersOverview();
        $workersOverview->generatedAt = $now;
        $workersOverview->monitoringEnabled = $settings->enabled;
        $workersOverview->loops = $workerDefinitions->loops;
        $workersOverview->sequenceWindowSeconds = self::OVERVIEW_WINDOW_SECONDS;
        $workersOverview->stepSeconds = self::OVERVIEW_STEP_SECONDS;
        $workersOverview->recentWindowSeconds = self::RECENT_WINDOW_SECONDS;
        $overviewInterval = new TimeInterval($from, $now);

        $workerStatBuckets = new WorkerStatBuckets();
        $workerTransportSamples = new WorkerTransportSamples();
        $liveWorkerProcesses = new WorkerProcesses();
        $statHistories = new WorkerTransportHistories();
        $sampleHistories = new WorkerTransportHistories();
        $totalSampleHistory = new WorkerTransportHistory();
        $workerTransportThroughputs = new WorkerTransportThroughputs();
        if ($settings->enabled) {
            $workerTransportThroughputs = WorkerStatBuckets::getService()->findThroughputsByTransport($now);
            WorkerTransportSamples::getService()->addWorkerSecondsToThroughputs($workerTransportThroughputs, $now);
            $totalSampleHistory = WorkerTransportSamples::getService()->findTotalSampleSequences($from, $now, self::OVERVIEW_STEP_SECONDS);
            $workerStatBuckets = WorkerStatBuckets::getService()->findWorkerStatBucketsInWindow($from, $now);
            $workerTransportSamples = WorkerTransportSamples::getService()->findWorkerTransportSamplesInWindow($from, $now);
            $liveWorkerProcesses = $this->findLiveWorkerProcesses($now, $settings);
            $statHistories = WorkerStatBuckets::getService()->findStatSequencesByTransport($from, $now, self::OVERVIEW_STEP_SECONDS);
            $sampleHistories = WorkerTransportSamples::getService()->findSampleSequencesByTransport($from, $now, self::OVERVIEW_STEP_SECONDS);
        }
        /** @var WorkerTransportSample[][] $samplesByTransport oldest first */
        $samplesByTransport = [];
        $lastSampledByCommandTimestamp = null;
        foreach ($workerTransportSamples->getElements() as $workerTransportSample) {
            $samplesByTransport[(string)$workerTransportSample->transport][] = $workerTransportSample;
            if (isset($workerTransportSample->lastSampledByCommandAt)) {
                $lastSampledByCommandTimestamp = max($lastSampledByCommandTimestamp ?? 0, $workerTransportSample->lastSampledByCommandAt->getTimestamp());
            }
        }
        $workersOverview->processes = $liveWorkerProcesses;
        $workersOverview->samplerActive = $lastSampledByCommandTimestamp !== null
            && $nowTimestamp - $lastSampledByCommandTimestamp <= WorkerDefinitionsService::SAMPLER_MAX_SILENCE_SECONDS;

        // the broker is asked only for transports without a fresh stored count
        $transportNamesToCount = [];
        foreach ($transportNames as $transportName) {
            $latestSample = isset($samplesByTransport[$transportName]) ? end($samplesByTransport[$transportName]) : null;
            if (!$this->isFreshSample($latestSample, $nowTimestamp, $settings) && $workerTransportsService->hasReceiver($transportName)) {
                $transportNamesToCount[] = $transportName;
            }
        }
        $workerQueueCounts = $transportNamesToCount !== []
            ? $this->getWorkerQueuesService()->countWaitingMessages($transportNamesToCount)
            : new WorkerQueueCounts();

        $recentFromTimestamp = $nowTimestamp - self::RECENT_WINDOW_SECONDS;
        foreach ($transportNames as $transportName) {
            $workerTransportOverview = new WorkerTransportOverview($transportName);
            $workerConsumerDefinition = $workerDefinitions->consumers->getByTransport($transportName);
            $workerTransportOverview->isDefined = $workerConsumerDefinition !== null;
            $workerTransportOverview->definition = $workerConsumerDefinition;
            if ($workerConsumerDefinition !== null) {
                $workerTransportOverview->isAutoscaled = $workerConsumerDefinition->isAutoscaled();
                $workerTransportOverview->effectiveMin = $workerConsumerDefinition->getEffectiveMin();
                $workerTransportOverview->effectiveMax = $workerConsumerDefinition->getEffectiveMax();
            }
            $samplesOfTransport = $samplesByTransport[$transportName] ?? [];
            $latestSample = $samplesOfTransport !== [] ? end($samplesOfTransport) : null;
            if ($this->isFreshSample($latestSample, $nowTimestamp, $settings)) {
                $workerTransportOverview->waiting = $latestSample->waitingLast ?? null;
                $workerTransportOverview->waitingState = $workerTransportOverview->waiting !== null
                    ? WorkerQueueCount::STATE_COUNTED
                    : WorkerQueueCount::STATE_UNKNOWN;
                $workerTransportOverview->waitingSampledAt = $latestSample->lastSampledAt;
            } elseif (($workerQueueCount = $workerQueueCounts->getByTransport($transportName)) !== null) {
                $workerTransportOverview->waiting = $workerQueueCount->waiting;
                $workerTransportOverview->waitingState = $workerQueueCount->state;
                $workerTransportOverview->waitingSampledAt = $now;
            }
            foreach ($liveWorkerProcesses->getElements() as $workerProcess) {
                if (!$workerProcess->consumesTransport($transportName)) {
                    continue;
                }
                $workerTransportOverview->liveProcessCount++;
                if (($workerProcess->currentMessageClass ?? null) !== null) {
                    $workerTransportOverview->inFlight++;
                }
                if (isset($workerProcess->restartRequestedAt)) {
                    $workerTransportOverview->restartPending = true;
                }
            }
            $this->addRecentStatistics($workerTransportOverview, $workerStatBuckets, $recentFromTimestamp);
            if (($workerTransportThroughput = $workerTransportThroughputs->getByTransport($transportName)) !== null) {
                $workerTransportOverview->throughputWindows = $workerTransportThroughput->throughputWindows;
            }
            $historyOfTransport = new WorkerTransportHistory($transportName);
            foreach ([$statHistories, $sampleHistories] as $workerTransportHistories) {
                if (($sourceHistory = $workerTransportHistories->getByTransport($transportName)) !== null) {
                    $historyOfTransport->addSequencesOf($sourceHistory);
                }
            }
            $workerTransportOverview->sequences = $this->getCompleteSequences(
                $historyOfTransport,
                [
                    WorkerTransportHistory::SEQUENCE_HANDLED,
                    WorkerTransportHistory::SEQUENCE_FAILED,
                    WorkerTransportHistory::SEQUENCE_WAITING,
                    WorkerTransportHistory::SEQUENCE_PROCESSES,
                ],
                $overviewInterval,
                self::OVERVIEW_STEP_SECONDS
            );
            $workerTransportOverview->stuckSince = $this->getStuckSince($samplesOfTransport, $nowTimestamp, $settings);
            $workerTransportOverview->state = $this->getTransportState(
                $workerTransportOverview,
                $workerTransportsService->hasReceiver($transportName)
            );
            $workersOverview->transports->add($workerTransportOverview);

            $workersOverview->totals->inFlight += $workerTransportOverview->inFlight;
            if ($workerTransportOverview->waiting !== null) {
                $workersOverview->totals->waiting = ($workersOverview->totals->waiting ?? 0) + $workerTransportOverview->waiting;
            }
        }
        // every transport with statistics counts, also one that left the configuration
        foreach ($workerTransportThroughputs->getElements() as $workerTransportThroughput) {
            $workersOverview->totals->throughputWindows->addCountsOf($workerTransportThroughput->throughputWindows);
        }
        $workersOverview->totals->liveProcessCount = $liveWorkerProcesses->count();
        foreach ($workerStatBuckets->getElements() as $workerStatBucket) {
            $workersOverview->totals->handledCountLastHour += $workerStatBucket->handledCount;
            $workersOverview->totals->failedCountLastHour += $workerStatBucket->getFailedCount();
        }
        foreach ([WorkerTransportHistory::SEQUENCE_FAILED, WorkerTransportHistory::SEQUENCE_HANDLED] as $sequenceName) {
            $totalSequence = $this->createTotalSequence($sequenceName, $statHistories, $from, $now, self::OVERVIEW_STEP_SECONDS);
            $totalSampleHistory->sequences->add($totalSequence);
        }
        $workersOverview->totals->sequences = $this->getCompleteSequences(
            $totalSampleHistory,
            [
                WorkerTransportHistory::SEQUENCE_WAITING,
                WorkerTransportHistory::SEQUENCE_IN_FLIGHT,
                WorkerTransportHistory::SEQUENCE_PROCESSES,
                WorkerTransportHistory::SEQUENCE_FAILED,
                WorkerTransportHistory::SEQUENCE_HANDLED,
            ],
            $overviewInterval,
            self::OVERVIEW_STEP_SECONDS
        );
        return $workersOverview;
    }

    /**
     * One transport in a window.
     *
     * @param DateTime|null $from Start of the window; null = one hour before $to
     * @param DateTime|null $to End of the window; null = now
     * @param int|null $stepSeconds One of {@see self::ALLOWED_STEP_SECONDS}; null = chosen by the window's length
     * @throws NotFoundException When neither the Messenger configuration nor the worker list knows the transport
     * @throws BadRequestException When the window or the step is not valid
     */
    public function getWorkerTransportDetail(string $transport, ?DateTime $from = null, ?DateTime $to = null, ?int $stepSeconds = null): WorkerTransportDetail
    {
        $workerTransportOverview = $this->getWorkersOverview()->transports->getByTransport($transport);
        if ($workerTransportOverview === null) {
            throw new NotFoundException(sprintf('No transport "%s" in the Messenger configuration or the worker list', $transport));
        }
        $interval = $this->createInterval($from, $to);
        $stepSeconds = $this->resolveStepSeconds($interval, $stepSeconds);
        $workerTransportDetail = new WorkerTransportDetail();
        $workerTransportDetail->transport = $workerTransportOverview;
        $workerTransportDetail->interval = $interval;
        $workerTransportDetail->stepSeconds = $stepSeconds;
        $workerTransportDetail->recentWindowSeconds = self::RECENT_WINDOW_SECONDS;
        $settings = $this->getSettings();
        $workerTransportHistory = new WorkerTransportHistory($transport);
        if ($settings->enabled) {
            foreach ($this->findHistories($interval, $stepSeconds, $transport) as $workerTransportHistories) {
                $historyOfTransport = $workerTransportHistories->getByTransport($transport);
                if ($historyOfTransport !== null) {
                    $workerTransportHistory->addSequencesOf($historyOfTransport);
                }
            }
            $workerTransportDetail->messageClasses = WorkerStatBuckets::getService()
                ->findWorkerMessageClassStats($interval->startDate, $interval->endDate, $transport);
            $now = DateTime::fromTimestamp($this->getCurrentTimestamp());
            foreach ($this->findLiveWorkerProcesses($now, $settings)->getElements() as $workerProcess) {
                if ($workerProcess->consumesTransport($transport)) {
                    $workerTransportDetail->processes->add($workerProcess);
                }
            }
        }
        $workerTransportDetail->sequences = $this->getCompleteSequences(
            $workerTransportHistory,
            [
                WorkerTransportHistory::SEQUENCE_HANDLED,
                WorkerTransportHistory::SEQUENCE_THROWN,
                WorkerTransportHistory::SEQUENCE_CAUGHT,
                WorkerTransportHistory::SEQUENCE_FAILED,
                WorkerTransportHistory::SEQUENCE_REDELIVERED,
                WorkerTransportHistory::SEQUENCE_DURATION_AVERAGE_MS,
                WorkerTransportHistory::SEQUENCE_DURATION_MAX_MS,
                WorkerTransportHistory::SEQUENCE_WAIT_AVERAGE_MS,
                WorkerTransportHistory::SEQUENCE_WAIT_MAX_MS,
                WorkerTransportHistory::SEQUENCE_WAITING,
                WorkerTransportHistory::SEQUENCE_IN_FLIGHT,
                WorkerTransportHistory::SEQUENCE_PROCESSES,
                WorkerTransportHistory::SEQUENCE_PROCESS_STARTS,
            ],
            $interval,
            $stepSeconds
        );
        return $workerTransportDetail;
    }

    /**
     * Every transport in a window: how many processes ran and started, what waited and ran, what was handled and
     * failed.
     *
     * @param DateTime|null $from Start of the window; null = one hour before $to
     * @param DateTime|null $to End of the window; null = now
     * @param int|null $stepSeconds One of {@see self::ALLOWED_STEP_SECONDS}; null = chosen by the window's length
     * @throws BadRequestException When the window or the step is not valid
     */
    public function getWorkersHistory(?DateTime $from = null, ?DateTime $to = null, ?int $stepSeconds = null): WorkersHistory
    {
        $interval = $this->createInterval($from, $to);
        $stepSeconds = $this->resolveStepSeconds($interval, $stepSeconds);
        $workersHistory = new WorkersHistory();
        $workersHistory->interval = $interval;
        $workersHistory->stepSeconds = $stepSeconds;
        $collectedHistories = new WorkerTransportHistories();
        foreach ([...$this->getWorkerTransportsService()->getConsumedTransportNames(), ...$this->getWorkerDefinitions()->consumers->getTransportNames()] as $transportName) {
            $collectedHistories->getOrCreateByTransport($transportName);
        }
        $totalHistory = new WorkerTransportHistory();
        if ($this->getSettings()->enabled) {
            $totalHistory = WorkerTransportSamples::getService()->findTotalSampleSequences($interval->startDate, $interval->endDate, $stepSeconds);
            foreach ($this->findHistories($interval, $stepSeconds, null) as $workerTransportHistories) {
                foreach ($workerTransportHistories->getElements() as $workerTransportHistory) {
                    // a transport that was removed from the configuration still has its past
                    $collectedHistories->getOrCreateByTransport($workerTransportHistory->transport)->addSequencesOf($workerTransportHistory);
                }
            }
        }
        foreach ($collectedHistories->getElements() as $collectedHistory) {
            $workerTransportHistory = new WorkerTransportHistory($collectedHistory->transport);
            $workerTransportHistory->sequences = $this->getCompleteSequences(
                $collectedHistory,
                self::HISTORY_SEQUENCE_NAMES,
                $interval,
                $stepSeconds
            );
            $workersHistory->transports->add($workerTransportHistory);
        }
        foreach ([WorkerTransportHistory::SEQUENCE_PROCESS_STARTS, WorkerTransportHistory::SEQUENCE_HANDLED, WorkerTransportHistory::SEQUENCE_FAILED] as $sequenceName) {
            $totalSequence = $this->createTotalSequence($sequenceName, $collectedHistories, $interval->startDate, $interval->endDate, $stepSeconds);
            $totalHistory->sequences->add($totalSequence);
        }
        $workersHistory->totals->sequences = $this->getCompleteSequences($totalHistory, self::HISTORY_SEQUENCE_NAMES, $interval, $stepSeconds);
        return $workersHistory;
    }

    /**
     * The step a window gets when none is asked for: a minute up to one hour, five minutes up to six hours, a quarter
     * of an hour up to a day, an hour beyond.
     */
    public function getDefaultStepSeconds(int $windowSeconds): int
    {
        return match (true) {
            $windowSeconds <= 3600 => 60,
            $windowSeconds <= 6 * 3600 => 300,
            $windowSeconds <= 86400 => 900,
            default => 3600,
        };
    }

    /**
     * @throws BadRequestException When the window is empty or reversed
     */
    protected function createInterval(?DateTime $from, ?DateTime $to): UtcTimeInterval
    {
        $toTimestamp = $to?->getTimestamp() ?? $this->getCurrentTimestamp();
        $fromTimestamp = $from?->getTimestamp() ?? $toTimestamp - self::DEFAULT_WINDOW_SECONDS;
        if ($fromTimestamp >= $toTimestamp) {
            throw new BadRequestException('The window is empty: "from" must be before "to"');
        }
        return new UtcTimeInterval(DateTime::fromTimestamp($fromTimestamp), DateTime::fromTimestamp($toTimestamp));
    }

    /**
     * @throws BadRequestException When the step is not allowed or the window needs too many points
     */
    protected function resolveStepSeconds(TimeInterval $interval, ?int $stepSeconds): int
    {
        $windowSeconds = (int)$interval->getDurationSeconds();
        $stepSeconds ??= $this->getDefaultStepSeconds($windowSeconds);
        if (!in_array($stepSeconds, self::ALLOWED_STEP_SECONDS, true)) {
            throw new BadRequestException('stepSeconds must be one of ' . implode(', ', self::ALLOWED_STEP_SECONDS));
        }
        if (intdiv($windowSeconds, $stepSeconds) > self::MAX_SEQUENCE_POINTS) {
            throw new BadRequestException(sprintf(
                'The window needs more than %d points per sequence at a step of %d s: use a larger step or a shorter window',
                self::MAX_SEQUENCE_POINTS,
                $stepSeconds
            ));
        }
        return $stepSeconds;
    }

    /**
     * The three sources of a window's sequences: statistics, samples and process starts.
     *
     * @return WorkerTransportHistories[]
     */
    protected function findHistories(TimeInterval $interval, int $stepSeconds, ?string $transport): array
    {
        return [
            WorkerStatBuckets::getService()->findStatSequencesByTransport($interval->startDate, $interval->endDate, $stepSeconds, $transport),
            WorkerTransportSamples::getService()->findSampleSequencesByTransport($interval->startDate, $interval->endDate, $stepSeconds, $transport),
            WorkerProcesses::getService()->findProcessStartSequencesByTransport($interval->startDate, $interval->endDate, $stepSeconds, $transport),
        ];
    }

    /**
     * The named sequences in the given order; one the history does not have is a series of zeros, or of gaps (null)
     * for a measure of {@see WorkerTransportHistory::GAP_SEQUENCES}.
     *
     * @param string[] $sequenceNames
     */
    protected function getCompleteSequences(WorkerTransportHistory $workerTransportHistory, array $sequenceNames, TimeInterval $interval, int $stepSeconds): DateTimeValueSequences
    {
        $dateTimeValueSequences = new DateTimeValueSequences();
        foreach ($sequenceNames as $sequenceName) {
            $dateTimeValueSequence = $workerTransportHistory->getSequence($sequenceName)
                ?? DateTimeValueSequence::fromValuesByTimestamp(
                    $sequenceName,
                    $interval->startDate,
                    $interval->endDate,
                    $stepSeconds,
                    [],
                    WorkerTransportHistory::isGapSequence($sequenceName)
                );
            $dateTimeValueSequences->add($dateTimeValueSequence);
        }
        return $dateTimeValueSequences;
    }

    /** One sequence of all transports together: the sum per step */
    protected function createTotalSequence(string $sequenceName, WorkerTransportHistories $workerTransportHistories, DateTime $from, DateTime $to, int $stepSeconds): DateTimeValueSequence
    {
        $totalsByTimestamp = [];
        foreach ($workerTransportHistories->getElements() as $workerTransportHistory) {
            foreach ($workerTransportHistory->getSequence($sequenceName)?->getValuesByTimestamp() ?? [] as $timestamp => $value) {
                $totalsByTimestamp[$timestamp] = ($totalsByTimestamp[$timestamp] ?? 0) + $value;
            }
        }
        return DateTimeValueSequence::fromValuesByTimestamp($sequenceName, $from, $to, $stepSeconds, $totalsByTimestamp);
    }

    /** The processes that are alive now, each with its display state */
    protected function findLiveWorkerProcesses(DateTime $now, WorkersSettings $settings): WorkerProcesses
    {
        $workerProcessesService = WorkerProcesses::getService();
        $liveWorkerProcesses = $workerProcessesService->findLiveWorkerProcesses($now, $settings->getLostAfterSeconds(), $settings->busySilentSeconds);
        $workerProcessesService->setDisplayStates($liveWorkerProcesses, $now, $settings->getLostAfterSeconds());
        return $liveWorkerProcesses;
    }

    /** Counts and averages of the recent window and the last error of the loaded hour */
    protected function addRecentStatistics(WorkerTransportOverview $workerTransportOverview, WorkerStatBuckets $workerStatBuckets, int $recentFromTimestamp): void
    {
        $durationTotalMs = 0;
        $waitTotalMs = 0;
        $waitCount = 0;
        $lastErrorTimestamp = 0;
        foreach ($workerStatBuckets->getElements() as $workerStatBucket) {
            if ($workerStatBucket->transport !== $workerTransportOverview->transport) {
                continue;
            }
            if (isset($workerStatBucket->lastErrorAt) && $workerStatBucket->lastErrorAt->getTimestamp() >= $lastErrorTimestamp) {
                $lastErrorTimestamp = $workerStatBucket->lastErrorAt->getTimestamp();
                $workerTransportOverview->lastErrorClass = $workerStatBucket->lastErrorClass ?? null;
                $workerTransportOverview->lastErrorMessage = $workerStatBucket->lastErrorMessage ?? null;
                $workerTransportOverview->lastErrorAt = $workerStatBucket->lastErrorAt;
            }
            if ($workerStatBucket->bucketStart->getTimestamp() < $recentFromTimestamp) {
                continue;
            }
            $workerTransportOverview->handledCount += $workerStatBucket->handledCount;
            $workerTransportOverview->thrownCount += $workerStatBucket->thrownCount;
            $workerTransportOverview->caughtCount += $workerStatBucket->caughtCount;
            $durationTotalMs += $workerStatBucket->durationTotalMs;
            $waitTotalMs += $workerStatBucket->waitTotalMs;
            $waitCount += $workerStatBucket->waitCount;
        }
        $workerTransportOverview->durationAverageMs = $workerTransportOverview->handledCount > 0
            ? intdiv($durationTotalMs, $workerTransportOverview->handledCount)
            : null;
        $workerTransportOverview->waitAverageMs = $waitCount > 0 ? intdiv($waitTotalMs, $waitCount) : null;
    }

    protected function isFreshSample(?WorkerTransportSample $workerTransportSample, int $nowTimestamp, WorkersSettings $settings): bool
    {
        return $workerTransportSample !== null && isset($workerTransportSample->lastSampledAt)
            && $nowTimestamp - $workerTransportSample->lastSampledAt->getTimestamp() <= $settings->sampleMaxAgeSeconds;
    }

    /**
     * Since when messages wait without a live process: the start of the unbroken run of such samples that ends with
     * the newest one. Null unless the newest sample is fresh and the run is longer than `stuckAfterSeconds`.
     *
     * @param WorkerTransportSample[] $samplesOfTransport Oldest first
     */
    protected function getStuckSince(array $samplesOfTransport, int $nowTimestamp, WorkersSettings $settings): ?DateTime
    {
        if ($samplesOfTransport === [] || !$this->isFreshSample(end($samplesOfTransport), $nowTimestamp, $settings)) {
            return null;
        }
        $stuckSince = null;
        foreach (array_reverse($samplesOfTransport) as $workerTransportSample) {
            $messagesWaitWithoutProcess = ($workerTransportSample->waitingLast ?? 0) > 0 && $workerTransportSample->processCountMax === 0;
            if (!$messagesWaitWithoutProcess) {
                break;
            }
            $stuckSince = $workerTransportSample->bucketStart;
        }
        if ($stuckSince === null || $nowTimestamp - $stuckSince->getTimestamp() < $settings->stuckAfterSeconds) {
            return null;
        }
        return $stuckSince;
    }

    protected function getTransportState(WorkerTransportOverview $workerTransportOverview, bool $hasReceiver): string
    {
        if (!$hasReceiver) {
            return WorkerTransportOverview::STATE_NOT_CONSUMED;
        }
        if ($workerTransportOverview->stuckSince !== null) {
            return WorkerTransportOverview::STATE_STUCK;
        }
        if (!$workerTransportOverview->isDefined) {
            return WorkerTransportOverview::STATE_UNDEFINED;
        }
        $waiting = $workerTransportOverview->waiting ?? 0;
        if ($waiting > 0 && $workerTransportOverview->inFlight >= $workerTransportOverview->liveProcessCount) {
            return WorkerTransportOverview::STATE_BACKLOG;
        }
        if ($waiting > 0 || $workerTransportOverview->inFlight > 0) {
            return WorkerTransportOverview::STATE_WORKING;
        }
        return WorkerTransportOverview::STATE_IDLE;
    }

    /** @return int Unix time; the method a test overrides */
    protected function getCurrentTimestamp(): int
    {
        return time();
    }

    protected function getSettings(): WorkersSettings
    {
        return WorkersSettings::fromEnvironment();
    }

    protected function getWorkerDefinitions(): WorkerDefinitions
    {
        /** @var WorkerDefinitionsService $workerDefinitionsService */
        $workerDefinitionsService = DDDService::instance()->getService(WorkerDefinitionsService::class);
        return $workerDefinitionsService->getWorkerDefinitions();
    }

    protected function getWorkerTransportsService(): WorkerTransportsService
    {
        return DDDService::instance()->getService(WorkerTransportsService::class);
    }

    protected function getWorkerQueuesService(): WorkerQueuesService
    {
        return DDDService::instance()->getService(WorkerQueuesService::class);
    }
}
