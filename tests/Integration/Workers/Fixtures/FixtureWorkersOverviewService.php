<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Workers\Fixtures;

use DDD\Domain\Common\Entities\Workers\WorkerDefinitions;
use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Domain\Common\Services\WorkerQueuesService;
use DDD\Domain\Common\Services\WorkersOverviewService;
use DDD\Domain\Common\Services\WorkerTransportsService;

/** The overview over a clock, settings, definitions, transports and queue counts the test sets */
class FixtureWorkersOverviewService extends WorkersOverviewService
{
    public int $currentTimestamp = 0;

    public function __construct(
        public WorkersSettings $workersSettings,
        public WorkerDefinitions $workerDefinitions,
        public WorkerTransportsService $workerTransportsService,
        public FixtureWorkerQueuesService $fixtureWorkerQueuesService,
    ) {
    }

    protected function getCurrentTimestamp(): int
    {
        return $this->currentTimestamp;
    }

    protected function getSettings(): WorkersSettings
    {
        return $this->workersSettings;
    }

    protected function getWorkerDefinitions(): WorkerDefinitions
    {
        return $this->workerDefinitions;
    }

    protected function getWorkerTransportsService(): WorkerTransportsService
    {
        return $this->workerTransportsService;
    }

    protected function getWorkerQueuesService(): WorkerQueuesService
    {
        return $this->fixtureWorkerQueuesService;
    }
}
