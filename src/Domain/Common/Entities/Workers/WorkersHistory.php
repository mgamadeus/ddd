<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Charts\TimeInterval;

/**
 * What the workers of every transport did in a window: per transport the sequences processes, processStarts, waiting,
 * inFlight, handled and failed ({@see WorkerTransportHistory}).
 */
class WorkersHistory extends ValueObject
{
    public TimeInterval $interval;

    /** @var int Bucket size of every sequence in seconds */
    public int $stepSeconds = 60;

    public WorkerTransportHistories $transports;

    public function __construct()
    {
        $this->interval = new TimeInterval();
        $this->transports = new WorkerTransportHistories();
        parent::__construct();
    }
}
