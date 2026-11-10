<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Workers\Fixtures;

use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Domain\Common\Services\WorkerMonitoringService;
use DDD\Domain\Common\Services\WorkerQueuesService;
use Throwable;

/** The monitoring of one simulated worker process: a clock, settings and queue counts the test sets */
class FixtureWorkerMonitoringService extends WorkerMonitoringService
{
    public float $currentTime = 0.0;
    public string $messageLogMode = WorkersSettings::MESSAGE_LOG_ALL;
    public ?Throwable $lastFailure = null;
    public int $simulatedProcessId = 1;

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
}
