<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequences;
use DDD\Domain\Common\Entities\Charts\UtcTimeInterval;

/**
 * One transport in a window: its overview row, every sequence ({@see WorkerTransportHistory} names), what its workers
 * did per message class, and its live processes.
 */
class WorkerTransportDetail extends ValueObject
{
    public WorkerTransportOverview $transport;

    public UtcTimeInterval $interval;

    /** @var int Bucket size of every sequence in seconds */
    public int $stepSeconds = 60;

    public DateTimeValueSequences $sequences;

    public WorkerMessageClassStats $messageClasses;

    /** @var WorkerProcesses The live processes, each with its display state */
    public WorkerProcesses $processes;

    public function __construct()
    {
        $this->transport = new WorkerTransportOverview();
        $this->interval = new UtcTimeInterval();
        $this->sequences = new DateTimeValueSequences();
        $this->messageClasses = new WorkerMessageClassStats();
        $this->processes = new WorkerProcesses();
        parent::__construct();
    }
}
