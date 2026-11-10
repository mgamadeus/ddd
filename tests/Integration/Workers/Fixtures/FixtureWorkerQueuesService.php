<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Workers\Fixtures;

use DDD\Domain\Common\Entities\Workers\WorkerQueueCount;
use DDD\Domain\Common\Entities\Workers\WorkerQueueCounts;
use DDD\Domain\Common\Services\WorkerQueuesService;

/** Answers queue counts a test set, without a broker */
class FixtureWorkerQueuesService extends WorkerQueuesService
{
    /** @var int[] Waiting messages by transport; a transport without an entry answers UNKNOWN */
    public array $waitingByTransport = [];

    public function countWaitingMessages(array $transports): WorkerQueueCounts
    {
        $workerQueueCounts = new WorkerQueueCounts();
        foreach ($transports as $transport) {
            if (isset($this->waitingByTransport[$transport])) {
                $workerQueueCounts->addCount($transport, WorkerQueueCount::STATE_COUNTED, $this->waitingByTransport[$transport]);
            } else {
                $workerQueueCounts->addCount($transport, WorkerQueueCount::STATE_UNKNOWN);
            }
        }
        return $workerQueueCounts;
    }
}
