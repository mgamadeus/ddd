<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequences;

/**
 * The workers of all transports together, now and over the last hour.
 */
class WorkersTotals extends ValueObject
{
    /** @var int|null Messages waiting in all queues that could be counted; null when none could */
    public ?int $waiting = null;

    public int $inFlight = 0;

    public int $liveProcessCount = 0;

    public int $failedCountLastHour = 0;

    public int $handledCountLastHour = 0;

    /**
     * @var DateTimeValueSequences The last hour in minutes: waiting, inFlight, processes (the transports' samples of
     * the minute added up; null in a minute without a sample), failed, handled
     */
    public DateTimeValueSequences $sequences;

    /**
     * @var WorkerThroughputWindows All transports together in the last 5 minutes, 15 minutes, hour and day. A process
     * that consumes several transports counts its worker seconds for each of them
     */
    public WorkerThroughputWindows $throughputWindows;

    public function __construct()
    {
        $this->sequences = new DateTimeValueSequences();
        $this->throughputWindows = WorkerThroughputWindows::fromWindowSeconds();
        parent::__construct();
    }
}
