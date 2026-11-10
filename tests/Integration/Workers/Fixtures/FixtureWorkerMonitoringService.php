<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Workers\Fixtures;

use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Domain\Common\Services\WorkerMonitoringService;
use DDD\Domain\Common\Services\WorkerQueuesService;
use RuntimeException;
use Throwable;

/** The monitoring of one simulated worker process: a clock, settings and queue counts the test sets */
class FixtureWorkerMonitoringService extends WorkerMonitoringService
{
    public float $currentTime = 0.0;
    public string $messageLogMode = WorkersSettings::MESSAGE_LOG_ALL;
    public ?Throwable $lastFailure = null;
    public int $simulatedProcessId = 1;
    public int $failuresReportedToIssuesLog = 0;
    public bool $failNextProcessWrite = false;

    public function __construct(public FixtureWorkerQueuesService $fixtureWorkerQueuesService, WorkersSettings $workersSettings)
    {
        $this->workersSettings = $workersSettings;
    }

    protected function getHostName(): string
    {
        return 'test-host';
    }

    /** Several simulated processes live in one PHP process */
    protected function getProcessId(): int
    {
        return $this->simulatedProcessId;
    }

    /** The heartbeat's process row and sample, whatever the interval */
    public function tickHeartbeat(): void
    {
        $this->lastHeartbeatAt = 0.0;
        $this->lastFlushAt = $this->currentTime;
        $this->tick();
    }

    protected function getCurrentTime(): float
    {
        return $this->currentTime;
    }

    protected function getMessageLogMode(string $transport): string
    {
        return $this->messageLogMode;
    }

    protected function getIssueGroupHash(Throwable $throwable): ?string
    {
        return 'hash-' . $throwable->getMessage();
    }

    protected function getWorkerQueuesService(): WorkerQueuesService
    {
        return $this->fixtureWorkerQueuesService;
    }

    protected function pauseAfterFailure(Throwable $throwable): void
    {
        $this->lastFailure = $throwable;
        parent::pauseAfterFailure($throwable);
    }

    protected function reportFailureToIssuesLog(Throwable $throwable): void
    {
        $this->failuresReportedToIssuesLog++;
    }

    protected function writeProcess(string $state, float $now, ?string $stopReason = null): void
    {
        if ($this->failNextProcessWrite) {
            $this->failNextProcessWrite = false;
            throw new RuntimeException('simulated failed write');
        }
        parent::writeProcess($state, $now, $stopReason);
    }
}
