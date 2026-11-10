<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Workers\Fixtures;

use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Domain\Common\Services\WorkerDefinitionsService;
use DDD\Domain\Common\Services\WorkerTransportsService;

/**
 * The definitions service on a given file and a given transport wiring, without a container.
 */
class FixtureWorkerDefinitionsService extends WorkerDefinitionsService
{
    public function __construct(
        protected string $fixtureDefinitionFilePath,
        protected WorkerTransportsService $fixtureWorkerTransportsService,
        protected ?WorkersSettings $fixtureWorkersSettings = null,
    ) {
    }

    public function getSettings(): WorkersSettings
    {
        return $this->fixtureWorkersSettings ?? new WorkersSettings();
    }

    public function getDefinitionFilePath(): string
    {
        return $this->fixtureDefinitionFilePath;
    }

    protected function getWorkerTransportsService(): WorkerTransportsService
    {
        return $this->fixtureWorkerTransportsService;
    }
}
