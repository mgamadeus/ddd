<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The workers of the application at a glance: every transport with its definition, queue, processes and state, the
 * totals, the live processes and the loops of the worker list.
 */
class WorkersOverview extends ValueObject
{
    public ?DateTime $generatedAt = null;

    /** @var bool The monitoring is switched on; off = only definitions and live queue counts are shown */
    public bool $monitoringEnabled = false;

    /** @var bool The sampler command wrote within the last minutes */
    public bool $samplerActive = false;

    /** @var int Seconds the sequences and the `…LastHour` totals cover */
    public int $sequenceWindowSeconds = 3600;

    /** @var int Bucket size of the sequences in seconds */
    public int $stepSeconds = 60;

    /** @var int Seconds the counts and averages of a transport cover (handledCount, thrownCount, caughtCount, durationAverageMs, waitAverageMs) */
    public int $recentWindowSeconds = 900;

    public WorkersTotals $totals;

    public WorkerTransportOverviews $transports;

    /** @var WorkerProcesses The live processes of all transports, each with its display state */
    public WorkerProcesses $processes;

    public WorkerLoopDefinitions $loops;

    public function __construct()
    {
        $this->totals = new WorkersTotals();
        $this->transports = new WorkerTransportOverviews();
        $this->processes = new WorkerProcesses();
        $this->loops = new WorkerLoopDefinitions();
        parent::__construct();
    }
}
