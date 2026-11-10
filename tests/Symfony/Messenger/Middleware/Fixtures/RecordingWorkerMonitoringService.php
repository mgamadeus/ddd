<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Messenger\Middleware\Fixtures;

use DDD\Domain\Common\Services\WorkerMonitoringService;
use Symfony\Component\Messenger\Envelope;
use Throwable;

/** Records what the middleware reports instead of measuring */
class RecordingWorkerMonitoringService extends WorkerMonitoringService
{
    /** @var string[] */
    public array $calls = [];
    public bool $startThrows = false;

    public function startMessage(Envelope $envelope): void
    {
        $this->calls[] = 'start';
        if ($this->startThrows) {
            throw new \RuntimeException('monitoring is broken');
        }
    }

    public function finishMessage(?Throwable $thrownThrowable = null): void
    {
        $this->calls[] = $thrownThrowable === null ? 'finish' : 'finish:' . $thrownThrowable->getMessage();
    }
}
