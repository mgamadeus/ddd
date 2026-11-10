<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Workers;

use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatements;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Workers\WorkerConsumerDefinition;
use DDD\Domain\Common\Entities\Workers\WorkerDefinitions;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRun;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRuns;
use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Domain\Common\Entities\Workers\WorkerQueueCount;
use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Domain\Common\Entities\Workers\WorkerStatBucket;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistory;
use DDD\Domain\Common\Entities\Workers\WorkerTransportOverview;
use DDD\Domain\Common\Entities\Workers\WorkerTransportSample;
use DDD\Domain\Common\Services\WorkerTransportsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Integration\IntegrationTestCase;
use DDD\Tests\Integration\Workers\Fixtures\FixtureWorkerAppMessage;
use DDD\Tests\Integration\Workers\Fixtures\FixtureWorkerMonitoringService;
use DDD\Tests\Integration\Workers\Fixtures\FixtureWorkerQueuesService;
use DDD\Tests\Integration\Workers\Fixtures\FixtureWorkersOverviewService;
use DDD\Symfony\EventListeners\WorkerMonitoringSubscriber;
use DDD\Symfony\Messenger\Middleware\WorkerMonitoringMiddleware;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * The worker monitoring's writes on both engines: additive statistics buckets from several processes, message runs
 * by log mode, the process row (heartbeat, restart request, stop reason), merged transport samples and the prune.
 */
class WorkerMonitoringTest extends IntegrationTestCase
{
    public const array WORKER_ENTITY_CLASSES = [WorkerStatBucket::class, WorkerTransportSample::class, WorkerProcess::class, WorkerMessageRun::class];
    /** 2030-01-01 00:00:10 UTC: ten seconds into a minute */
    public const int START_TIME = 1893456010;
    public const string TRANSPORT = 'send_mail';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        $connection = static::connection();
        $databaseModels = array_map(static fn(string $entityClass): DatabaseModel => DatabaseModel::fromEntityClass($entityClass), self::WORKER_ENTITY_CLASSES);
        foreach ($databaseModels as $databaseModel) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . static::quoteIdentifier($databaseModel->sqlTableName)
                . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : ''));
        }
        if (static::currentDriver() === 'pdo_pgsql') {
            $statements = [];
            foreach ($databaseModels as $databaseModel) {
                foreach (static::dialect()->ddlRenderer()->renderCreate($databaseModel)->getElements() as $statement) {
                    $statements[] = $statement;
                }
            }
            foreach (DdlStatements::fromStatements(...$statements)->sortedByPhase() as $statement) {
                $connection->executeStatement($statement->sql);
            }
            return;
        }
        foreach ($databaseModels as $databaseModel) {
            foreach (static::splitSqlStatements($databaseModel->getSql()) as $statement) {
                $connection->executeStatement($statement);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            foreach (self::WORKER_ENTITY_CLASSES as $entityClass) {
                static::connection()->executeStatement('DROP TABLE IF EXISTS '
                    . static::quoteIdentifier(DatabaseModel::fromEntityClass($entityClass)->sqlTableName)
                    . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : ''));
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::WORKER_ENTITY_CLASSES as $entityClass) {
            static::connection()->executeStatement('DELETE FROM ' . static::quoteIdentifier(DatabaseModel::fromEntityClass($entityClass)->sqlTableName));
        }
    }

    protected function createMonitoring(int $simulatedProcessId = 1, ?WorkersSettings $workersSettings = null): FixtureWorkerMonitoringService
    {
        $workerMonitoringService = new FixtureWorkerMonitoringService(
            new FixtureWorkerQueuesService(),
            $workersSettings ?? new WorkersSettings(enabled: true)
        );
        $workerMonitoringService->currentTime = (float)self::START_TIME;
        $workerMonitoringService->simulatedProcessId = $simulatedProcessId;
        return $workerMonitoringService;
    }

    protected function runMessage(FixtureWorkerMonitoringService $workerMonitoringService, float $durationSeconds, ?\Throwable $thrown = null, ?\Throwable $caught = null, array $stamps = []): void
    {
        $workerMonitoringService->startMessage(new Envelope(new stdClass(), [new ReceivedStamp(self::TRANSPORT), ...$stamps]));
        $workerMonitoringService->currentTime += $durationSeconds;
        if ($caught !== null) {
            $workerMonitoringService->reportCaughtThrowable($caught);
        }
        $workerMonitoringService->finishMessage($thrown);
    }

    /** @return array<int, array<string, mixed>> */
    protected function rows(string $entityClass, string $orderBy = 'id'): array
    {
        return static::connection()->fetchAllAssociative(
            'SELECT * FROM ' . static::quoteIdentifier(DatabaseModel::fromEntityClass($entityClass)->sqlTableName)
            . ' ORDER BY ' . static::quoteIdentifier($orderBy)
        );
    }

    public function testBucketsOfSeveralProcessesAddUpAndRunsFollowTheLogMode(): void
    {
        $firstProcess = $this->createMonitoring(1);
        $secondProcess = $this->createMonitoring(2);
        $secondProcess->messageLogMode = WorkersSettings::MESSAGE_LOG_FAILURES;

        $this->runMessage($firstProcess, 0.2);
        $this->runMessage($firstProcess, 0.5, caught: new RuntimeException('caught one'));
        $this->runMessage($secondProcess, 0.1);
        $this->runMessage(
            $secondProcess,
            1.5,
            thrown: new HandlerFailedException(new Envelope(new stdClass()), [new RuntimeException('thrown one')]),
            stamps: [new RedeliveryStamp(1)]
        );
        $secondProcess->reportWillRetryOfLastMessage(true);
        $firstProcess->flush();
        $secondProcess->flush();

        self::assertNull($firstProcess->lastFailure, (string)$firstProcess->lastFailure?->getMessage());
        self::assertNull($secondProcess->lastFailure, (string)$secondProcess->lastFailure?->getMessage());
        $buckets = $this->rows(WorkerStatBucket::class);
        self::assertCount(1, $buckets);
        self::assertSame(4, (int)$buckets[0]['handledCount']);
        self::assertSame(1, (int)$buckets[0]['thrownCount']);
        self::assertSame(1, (int)$buckets[0]['caughtCount']);
        self::assertSame(1, (int)$buckets[0]['redeliveredCount']);
        self::assertSame(2300, (int)$buckets[0]['durationTotalMs']);
        self::assertSame(1500, (int)$buckets[0]['durationMaxMs']);
        self::assertSame(RuntimeException::class, $buckets[0]['lastErrorClass']);
        self::assertSame('thrown one', $buckets[0]['lastErrorMessage']);
        self::assertSame(stdClass::class, $buckets[0]['messageClass']);

        $runs = $this->rows(WorkerMessageRun::class);
        self::assertSame(
            [WorkerMessageRun::OUTCOME_HANDLED, WorkerMessageRun::OUTCOME_CAUGHT, WorkerMessageRun::OUTCOME_THROWN],
            array_column($runs, 'outcome')
        );
        self::assertSame(2, (int)$runs[2]['attempt']);
        self::assertTrue((bool)$runs[2]['willRetry']);
        self::assertSame('hash-thrown one', $runs[2]['issueGroupHash']);
        self::assertNull($runs[0]['errorClass']);
    }

    public function testProcessRowHeartbeatRestartRequestAndStopReason(): void
    {
        $workerMonitoringService = $this->createMonitoring(1);
        $workerMonitoringService->fixtureWorkerQueuesService->waitingByTransport = [self::TRANSPORT => 7];
        $workerMonitoringService->registerWorker([self::TRANSPORT]);
        self::assertNull($workerMonitoringService->lastFailure, (string)$workerMonitoringService->lastFailure?->getMessage());

        $workerMonitoringService->currentTime += 2;
        $workerMonitoringService->startMessage(new Envelope(new stdClass(), [new ReceivedStamp(self::TRANSPORT)]));
        $processes = $this->rows(WorkerProcess::class);
        $ownRow = array_values(array_filter($processes, static fn(array $row): bool => $row['processKey'] === $workerMonitoringService->getProcessKey()))[0];
        self::assertSame(stdClass::class, $ownRow['currentMessageClass']);
        self::assertSame(WorkerProcess::STATE_RUNNING, $ownRow['state']);

        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $asked = WorkerProcesses::getService()->requestRestartOfWorkerProcesses(
                DateTime::fromTimestamp((int)$workerMonitoringService->currentTime),
                30,
                3600,
                self::TRANSPORT
            );
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        self::assertGreaterThanOrEqual(1, $asked->count());

        $workerMonitoringService->currentTime += 1;
        $workerMonitoringService->finishMessage();
        $workerMonitoringService->currentTime += 11;
        self::assertTrue($workerMonitoringService->tick(), (string)$workerMonitoringService->lastFailure?->getMessage());

        $samples = $this->rows(WorkerTransportSample::class);
        self::assertCount(1, $samples);
        self::assertSame(7, (int)$samples[0]['waitingLast']);
        self::assertSame(1, (int)$samples[0]['processCountLast']);
        self::assertNull($samples[0]['lastSampledByCommandAt']);

        $workerMonitoringService->closeWorker();
        $ownRow = array_values(array_filter($this->rows(WorkerProcess::class), static fn(array $row): bool => $row['processKey'] === $workerMonitoringService->getProcessKey()))[0];
        self::assertSame(WorkerProcess::STATE_STOPPED, $ownRow['state']);
        self::assertSame(WorkerProcess::STOP_REASON_RESTART_REQUESTED, $ownRow['stopReason']);
        self::assertNotNull($ownRow['restartRequestedAt'], 'the heartbeat upsert must not overwrite the restart request');
        self::assertNull($ownRow['currentMessageClass']);
        self::assertSame(1, (int)$ownRow['handledCount']);
        self::assertNotNull($ownRow['firstMessageAt']);
    }

    /**
     * A message class is a value object class: its name is stored as a string in the process row, the buckets and
     * the runs — the entity mapper must write it as the string it is.
     */
    public function testAValueObjectMessageClassNameIsStoredAsAString(): void
    {
        $workerMonitoringService = $this->createMonitoring(1);
        $workerMonitoringService->registerWorker([self::TRANSPORT]);
        $workerMonitoringService->currentTime += 2;
        $workerMonitoringService->startMessage(new Envelope(new FixtureWorkerAppMessage(), [new ReceivedStamp(self::TRANSPORT)]));
        self::assertNull($workerMonitoringService->lastFailure, (string)$workerMonitoringService->lastFailure?->getMessage());
        self::assertSame(FixtureWorkerAppMessage::class, $this->rows(WorkerProcess::class)[0]['currentMessageClass']);

        $workerMonitoringService->currentTime += 1;
        $workerMonitoringService->finishMessage(new RuntimeException('boom'));
        $workerMonitoringService->flush();
        self::assertNull($workerMonitoringService->lastFailure, (string)$workerMonitoringService->lastFailure?->getMessage());
        self::assertSame(FixtureWorkerAppMessage::class, $this->rows(WorkerStatBucket::class)[0]['messageClass']);
        $run = $this->rows(WorkerMessageRun::class)[0];
        self::assertSame(FixtureWorkerAppMessage::class, $run['messageClass']);
        self::assertSame(RuntimeException::class, $run['errorClass']);
    }

    /**
     * A failed write pauses the monitoring for less than it takes to count as lost while the heartbeat goes on, is reported to the issues log
     * once per process, and a process closed as lost that beats again is running again.
     */
    public function testAFailedWritePausesShortlyAndALostProcessThatBeatsAgainIsRunning(): void
    {
        $workerMonitoringService = $this->createMonitoring(1);
        $settings = $workerMonitoringService->getSettings();
        $secondsUntilLost = $settings->heartbeatSeconds * FixtureWorkerMonitoringService::CLOSE_LOST_AFTER_HEARTBEATS;
        $workerMonitoringService->registerWorker([self::TRANSPORT]);

        $workerMonitoringService->failNextProcessWrite = true;
        $workerMonitoringService->currentTime += $settings->heartbeatSeconds;
        $workerMonitoringService->tickHeartbeat();
        self::assertNotNull($workerMonitoringService->lastFailure);
        self::assertFalse($workerMonitoringService->isEnabled());
        self::assertSame(1, $workerMonitoringService->failuresReportedToIssuesLog);

        // the heartbeat goes on during the pause
        $workerMonitoringService->currentTime += $settings->heartbeatSeconds;
        $workerMonitoringService->tickHeartbeat();
        self::assertFalse($workerMonitoringService->isEnabled());
        self::assertSame(
            (int)$workerMonitoringService->currentTime,
            DateTime::fromString((string)$this->rows(WorkerProcess::class)[0]['lastSeenAt'])->getTimestamp()
        );

        $workerMonitoringService->currentTime += $secondsUntilLost - 2 * $settings->heartbeatSeconds - 1;
        self::assertTrue($workerMonitoringService->isEnabled(), 'the pause ends before the process counts as lost');

        // closed as lost by another process' prune, then the process beats again
        $workerMonitoringService->currentTime += $secondsUntilLost;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $workerMonitoringService->prune();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $row = $this->rows(WorkerProcess::class)[0];
        self::assertSame(WorkerProcess::STATE_STOPPED, $row['state']);
        self::assertSame(WorkerProcess::STOP_REASON_LOST, $row['stopReason']);

        $workerMonitoringService->failNextProcessWrite = true;
        $workerMonitoringService->tickHeartbeat();
        self::assertSame(1, $workerMonitoringService->failuresReportedToIssuesLog, 'once per process');
        $workerMonitoringService->currentTime += $secondsUntilLost;
        $workerMonitoringService->tickHeartbeat();
        $row = $this->rows(WorkerProcess::class)[0];
        self::assertSame(WorkerProcess::STATE_RUNNING, $row['state']);
        self::assertNull($row['stopReason']);
        self::assertNull($row['stoppedAt']);
    }

    public function testSamplesOfOneMinuteKeepTheMaximumAndTheCommandsTime(): void
    {
        $workerMonitoringService = $this->createMonitoring(1);
        $workerMonitoringService->fixtureWorkerQueuesService->waitingByTransport = [self::TRANSPORT => 40];
        $workerMonitoringService->sampleTransports([self::TRANSPORT, 'no_count'], true);
        $workerMonitoringService->currentTime += 20;
        $workerMonitoringService->fixtureWorkerQueuesService->waitingByTransport = [self::TRANSPORT => 5];
        $workerMonitoringService->sampleTransports([self::TRANSPORT, 'no_count'], false);

        $samples = $this->rows(WorkerTransportSample::class, 'transport');
        self::assertSame(['no_count', self::TRANSPORT], array_column($samples, 'transport'));
        self::assertNull($samples[0]['waitingMax']);
        self::assertSame(40, (int)$samples[1]['waitingMax']);
        self::assertSame(5, (int)$samples[1]['waitingLast']);
        self::assertNotNull($samples[1]['lastSampledByCommandAt'], 'a heartbeat sample must not erase the sampler command\'s time');
        self::assertSame(0, (int)$samples[1]['processCountMax']);
    }

    public function testPruneDeletesOldRowsAndClosesLostProcesses(): void
    {
        $workerMonitoringService = $this->createMonitoring(1);
        $workerMonitoringService->registerWorker([self::TRANSPORT]);
        $this->runMessage($workerMonitoringService, 0.1);
        $this->runMessage($workerMonitoringService, 0.1, caught: new RuntimeException('kept longer'));
        $workerMonitoringService->flush();
        $workerMonitoringService->sampleTransports([self::TRANSPORT], true);
        self::assertNull($workerMonitoringService->lastFailure, (string)$workerMonitoringService->lastFailure?->getMessage());

        // five days later: the handled run is past its 3 days, the failed one is not; the process never said goodbye
        $workerMonitoringService->currentTime += 5 * 86400;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $workerMonitoringService->prune();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        self::assertSame([WorkerMessageRun::OUTCOME_CAUGHT], array_column($this->rows(WorkerMessageRun::class), 'outcome'));
        self::assertCount(1, $this->rows(WorkerStatBucket::class));
        $processes = $this->rows(WorkerProcess::class);
        self::assertNotEmpty($processes);
        foreach ($processes as $process) {
            self::assertSame(WorkerProcess::STATE_STOPPED, $process['state']);
            self::assertSame(WorkerProcess::STOP_REASON_LOST, $process['stopReason']);
        }

        // a month later everything is past its retention
        $workerMonitoringService->currentTime += 30 * 86400;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $workerMonitoringService->prune();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        self::assertSame([], $this->rows(WorkerMessageRun::class));
        self::assertSame([], $this->rows(WorkerStatBucket::class));
        self::assertSame([], $this->rows(WorkerTransportSample::class));
        self::assertSame([], $this->rows(WorkerProcess::class));
    }

    /** A real Symfony Worker consumes three messages (one handler throws) and stops at its message limit */
    public function testARealWorkerRunIsMeasuredFromStartToStop(): void
    {
        $workerMonitoringService = $this->createMonitoring(1);
        $inMemoryTransport = new InMemoryTransport();
        foreach ([1, 2, 3] as $number) {
            $message = new stdClass();
            $message->number = $number;
            $inMemoryTransport->send(new Envelope($message));
        }
        $messageBus = new MessageBus([
            new WorkerMonitoringMiddleware($workerMonitoringService),
            new HandleMessageMiddleware(new HandlersLocator([
                stdClass::class => [static function (stdClass $message) use ($workerMonitoringService): void {
                    $workerMonitoringService->currentTime += 0.25;
                    if ($message->number === 2) {
                        throw new RuntimeException('second one fails');
                    }
                }],
            ])),
        ]);
        $eventDispatcher = new EventDispatcher();
        $workerMonitoringSubscriber = new WorkerMonitoringSubscriber($workerMonitoringService);
        $eventDispatcher->addSubscriber($workerMonitoringSubscriber);
        $eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(3));
        $workerMonitoringService->setConsumeLimits(3, null, null, null);

        (new Worker([self::TRANSPORT => $inMemoryTransport], $messageBus, $eventDispatcher))->run(['sleep' => 0]);

        self::assertNull($workerMonitoringService->lastFailure, (string)$workerMonitoringService->lastFailure?->getMessage());
        $buckets = $this->rows(WorkerStatBucket::class);
        self::assertCount(1, $buckets);
        self::assertSame(self::TRANSPORT, $buckets[0]['transport']);
        self::assertSame(3, (int)$buckets[0]['handledCount']);
        self::assertSame(1, (int)$buckets[0]['thrownCount']);
        self::assertSame(750, (int)$buckets[0]['durationTotalMs']);
        $runs = $this->rows(WorkerMessageRun::class);
        self::assertSame(
            [WorkerMessageRun::OUTCOME_HANDLED, WorkerMessageRun::OUTCOME_THROWN, WorkerMessageRun::OUTCOME_HANDLED],
            array_column($runs, 'outcome')
        );
        self::assertSame('second one fails', $runs[1]['errorMessage']);
        self::assertFalse((bool)$runs[1]['willRetry']);
        self::assertSame($workerMonitoringService->getProcessKey(), $runs[0]['processKey']);
        $processes = $this->rows(WorkerProcess::class);
        self::assertCount(1, $processes);
        self::assertSame(self::TRANSPORT, $processes[0]['transport']);
        self::assertSame(WorkerProcess::STATE_STOPPED, $processes[0]['state']);
        self::assertSame(WorkerProcess::STOP_REASON_MESSAGE_LIMIT, $processes[0]['stopReason']);
        self::assertSame(3, (int)$processes[0]['handledCount']);
        self::assertSame(1, (int)$processes[0]['failedCount']);
    }

    /** @param string[] $definedTransports */
    protected function createOverviewService(FixtureWorkerMonitoringService $workerMonitoringService, array $definedTransports, array $consumedTransports): FixtureWorkersOverviewService
    {
        $workerDefinitions = new WorkerDefinitions();
        foreach ($definedTransports as $definedTransport) {
            $workerConsumerDefinition = new WorkerConsumerDefinition();
            $workerConsumerDefinition->transport = $definedTransport;
            $workerConsumerDefinition->min = 0;
            $workerConsumerDefinition->max = 4;
            $workerDefinitions->consumers->add($workerConsumerDefinition);
        }
        $dsns = array_fill_keys($consumedTransports, 'amqp://localhost/%2f/q');
        $workersOverviewService = new FixtureWorkersOverviewService(
            $workerMonitoringService->getSettings(),
            $workerDefinitions,
            new WorkerTransportsService($consumedTransports, [], $dsns, []),
            $workerMonitoringService->fixtureWorkerQueuesService
        );
        $workersOverviewService->currentTimestamp = (int)$workerMonitoringService->currentTime;
        return $workersOverviewService;
    }

    protected function withoutRights(callable $read): mixed
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $read();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function testOverviewDetailAndHistoryOfARunningAndAStuckTransport(): void
    {
        $workerMonitoringService = $this->createMonitoring(1);
        $workerMonitoringService->fixtureWorkerQueuesService->waitingByTransport = [self::TRANSPORT => 2, 'import_files' => 9];
        $workerMonitoringService->registerWorker([self::TRANSPORT]);
        // five minutes: every minute one handled message, one caught failure, and a command sample of both transports
        for ($minute = 0; $minute < 5; $minute++) {
            $this->runMessage($workerMonitoringService, 0.2);
            $this->runMessage($workerMonitoringService, 0.4, caught: new RuntimeException('caught ' . $minute));
            $workerMonitoringService->flush();
            $workerMonitoringService->tickHeartbeat();
            $workerMonitoringService->sampleTransports([self::TRANSPORT, 'import_files'], true);
            $workerMonitoringService->currentTime += 59.4;
        }
        $workerMonitoringService->currentTime -= 59.4;
        self::assertNull($workerMonitoringService->lastFailure, (string)$workerMonitoringService->lastFailure?->getMessage());
        $workersOverviewService = $this->createOverviewService($workerMonitoringService, [self::TRANSPORT, 'import_files', 'gone'], [self::TRANSPORT, 'import_files', 'undefined_one']);

        $workersOverview = $this->withoutRights(static fn() => $workersOverviewService->getWorkersOverview());

        self::assertTrue($workersOverview->monitoringEnabled);
        self::assertTrue($workersOverview->samplerActive);
        $sendMail = $workersOverview->transports->getByTransport(self::TRANSPORT);
        self::assertSame(2, $sendMail->waiting);
        self::assertSame(WorkerQueueCount::STATE_COUNTED, $sendMail->waitingState);
        self::assertSame(1, $sendMail->liveProcessCount);
        self::assertSame(10, $sendMail->handledCount);
        self::assertSame(5, $sendMail->caughtCount);
        self::assertSame(300, $sendMail->durationAverageMs);
        self::assertNull($sendMail->waitAverageMs);
        self::assertSame('caught 4', $sendMail->lastErrorMessage);
        self::assertSame(WorkerTransportOverview::STATE_WORKING, $sendMail->state);
        self::assertSame(4, $sendMail->effectiveMax);
        $handledSequence = $sendMail->sequences->getByUniqueKey(DateTimeValueSequence::uniqueKeyStatic(WorkerTransportHistory::SEQUENCE_HANDLED));
        self::assertCount(61, $handledSequence->getElements());
        self::assertSame(10.0, $handledSequence->getTotal());
        $importFiles = $workersOverview->transports->getByTransport('import_files');
        self::assertSame(9, $importFiles->waiting);
        self::assertSame(0, $importFiles->liveProcessCount);
        self::assertSame(WorkerTransportOverview::STATE_STUCK, $importFiles->state);
        self::assertNotNull($importFiles->stuckSince);
        self::assertSame(WorkerTransportOverview::STATE_NOT_CONSUMED, $workersOverview->transports->getByTransport('gone')->state);
        $undefined = $workersOverview->transports->getByTransport('undefined_one');
        self::assertSame(WorkerTransportOverview::STATE_UNDEFINED, $undefined->state);
        self::assertSame(WorkerQueueCount::STATE_UNKNOWN, $undefined->waitingState, 'no fresh sample: the broker was asked');
        self::assertSame(11, $workersOverview->totals->waiting);
        self::assertSame(10, $workersOverview->totals->handledCountLastHour);
        self::assertSame(5, $workersOverview->totals->failedCountLastHour);
        self::assertSame(1, $workersOverview->totals->liveProcessCount);
        self::assertCount(5, $workersOverview->totals->sequences->getElements());

        $workerTransportDetail = $this->withoutRights(static fn() => $workersOverviewService->getWorkerTransportDetail(self::TRANSPORT));
        self::assertSame(60, $workerTransportDetail->stepSeconds);
        self::assertCount(13, $workerTransportDetail->sequences->getElements());
        $sequenceTotals = [];
        foreach ($workerTransportDetail->sequences->getElements() as $dateTimeValueSequence) {
            $sequenceTotals[$dateTimeValueSequence->name] = $dateTimeValueSequence->getTotal();
            self::assertCount(61, $dateTimeValueSequence->getElements(), $dateTimeValueSequence->name);
        }
        self::assertSame(10.0, $sequenceTotals[WorkerTransportHistory::SEQUENCE_HANDLED]);
        self::assertSame(5.0, $sequenceTotals[WorkerTransportHistory::SEQUENCE_FAILED]);
        self::assertSame(1.0, $sequenceTotals[WorkerTransportHistory::SEQUENCE_PROCESS_STARTS]);
        self::assertSame(5 * 300.0, $sequenceTotals[WorkerTransportHistory::SEQUENCE_DURATION_AVERAGE_MS]);
        self::assertSame(5 * 2.0, $sequenceTotals[WorkerTransportHistory::SEQUENCE_WAITING]);
        self::assertSame(5 * 1.0, $sequenceTotals[WorkerTransportHistory::SEQUENCE_PROCESSES]);
        self::assertCount(1, $workerTransportDetail->messageClasses->getElements());
        $messageClassStat = $workerTransportDetail->messageClasses->getElements()[0];
        self::assertSame(stdClass::class, $messageClassStat->messageClass);
        self::assertSame(10, $messageClassStat->handledCount);
        self::assertSame(400, $messageClassStat->durationMaxMs);
        self::assertSame('caught 4', $messageClassStat->lastErrorMessage);
        self::assertCount(1, $workerTransportDetail->processes->getElements());
        self::assertSame(WorkerProcess::STATE_RUNNING, $workerTransportDetail->processes->getElements()[0]->displayState);

        $from = DateTime::fromTimestamp((int)$workerMonitoringService->currentTime - 6 * 3600);
        $workersHistory = $this->withoutRights(static fn() => $workersOverviewService->getWorkersHistory($from));
        self::assertSame(300, $workersHistory->stepSeconds);
        $importHistory = $workersHistory->transports->getByTransport('import_files');
        self::assertSame(9.0, max(array_values($importHistory->getSequence(WorkerTransportHistory::SEQUENCE_WAITING)->getValuesByTimestamp())));
        self::assertSame(0.0, $importHistory->getSequence(WorkerTransportHistory::SEQUENCE_PROCESSES)->getTotal());
        self::assertSame(10.0, $workersHistory->transports->getByTransport(self::TRANSPORT)->getSequence(WorkerTransportHistory::SEQUENCE_HANDLED)->getTotal());
        self::assertSame(1.0, $workersHistory->transports->getByTransport(self::TRANSPORT)->getSequence(WorkerTransportHistory::SEQUENCE_PROCESS_STARTS)->getTotal());

        $this->expectException(BadRequestException::class);
        $workersOverviewService->getWorkersHistory(DateTime::fromTimestamp((int)$workerMonitoringService->currentTime - 14 * 86400), null, 60);
    }

    public function testRunsAndProcessesOfATransportKeepTheirScopeUnderClientFilters(): void
    {
        $firstProcess = $this->createMonitoring(1);
        $firstProcess->registerWorker([self::TRANSPORT, 'send_mail_bulk']);
        $secondProcess = $this->createMonitoring(2);
        $secondProcess->registerWorker(['send_mailx']);
        $this->runMessage($firstProcess, 0.1);
        $secondProcess->startMessage(new Envelope(new stdClass(), [new ReceivedStamp('send_mailx')]));
        $secondProcess->finishMessage();
        $firstProcess->flush();
        $secondProcess->flush();

        $processes = $this->withoutRights(static fn() => WorkerProcesses::getService()->findWorkerProcessesOfTransport(self::TRANSPORT));
        self::assertSame([$firstProcess->getProcessKey()], array_map(static fn(WorkerProcess $workerProcess): string => $workerProcess->processKey, $processes->getElements()));
        self::assertCount(1, $this->withoutRights(static fn() => WorkerProcesses::getService()->findWorkerProcessesOfTransport('send_mail_bulk'))->getElements());
        self::assertCount(0, $this->withoutRights(static fn() => WorkerProcesses::getService()->findWorkerProcessesOfTransport('send_mai_'))->getElements());

        WorkerMessageRuns::setDefaultQueryOptionsSnapshot();
        try {
            $clientFilters = FiltersOptions::fromString("outcome eq 'HANDLED' or transport eq 'send_mailx'");
            WorkerMessageRuns::getDefaultQueryOptions()->setFilters($clientFilters);
            $runs = $this->withoutRights(static fn() => WorkerMessageRuns::getService()->findWorkerMessageRunsOfTransport(self::TRANSPORT));
        } finally {
            WorkerMessageRuns::restoreDefaultQueryOptionsSnapshot();
        }
        self::assertSame([self::TRANSPORT], array_map(static fn(WorkerMessageRun $workerMessageRun): string => $workerMessageRun->transport, $runs->getElements()));
    }
}
