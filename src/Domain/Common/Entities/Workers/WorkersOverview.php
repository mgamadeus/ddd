<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The workers of the application at a glance: every transport with its definition, queue, processes and state, the
 * totals and the loops of the worker list.
 */
class WorkersOverview extends ValueObject
{
    public ?DateTime $generatedAt = null;

    /** @var bool The monitoring is switched on; off = only definitions and live queue counts are shown */
    public bool $monitoringEnabled = false;

    /** @var bool The sampler command wrote within the last minutes */
    public bool $samplerActive = false;

    public WorkersTotals $totals;

    public WorkerTransportOverviews $transports;

    public WorkerLoopDefinitions $loops;

    public function __construct()
    {
        $this->totals = new WorkersTotals();
        $this->transports = new WorkerTransportOverviews();
        $this->loops = new WorkerLoopDefinitions();
        parent::__construct();
    }
}
