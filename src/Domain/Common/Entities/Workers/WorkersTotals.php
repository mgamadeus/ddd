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

    /** @var DateTimeValueSequences The last hour in minutes: waiting, inFlight, processes, failed, handled */
    public DateTimeValueSequences $sequences;

    public function __construct()
    {
        $this->sequences = new DateTimeValueSequences();
        parent::__construct();
    }
}
