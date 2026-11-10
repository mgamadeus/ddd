<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Charts\UtcTimeInterval;

/**
 * What the workers of every transport did in a window: per transport the sequences processes, processStarts, waiting,
 * inFlight, handled and failed ({@see WorkerTransportHistory}), and the same sequences for all transports together.
 */
class WorkersHistory extends ValueObject
{
    public UtcTimeInterval $interval;

    /** @var int Bucket size of every sequence in seconds */
    public int $stepSeconds = 60;

    public WorkerTransportHistories $transports;

    /**
     * @var WorkerTransportHistory All transports together (its transport name is empty). handled, failed and
     * processStarts are sums. waiting, inFlight and processes add up the transports' samples of one minute and show
     * the highest minute of a step — what was true at one moment, not the sum of maxima reached at different times
     */
    public WorkerTransportHistory $totals;

    public function __construct()
    {
        $this->interval = new UtcTimeInterval();
        $this->transports = new WorkerTransportHistories();
        $this->totals = new WorkerTransportHistory();
        parent::__construct();
    }
}
