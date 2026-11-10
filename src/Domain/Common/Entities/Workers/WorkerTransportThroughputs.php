<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property WorkerTransportThroughput[] $elements
 * @method WorkerTransportThroughput[] getElements()
 * @method WorkerTransportThroughput|null getByUniqueKey(string $uniqueKey)
 */
class WorkerTransportThroughputs extends ObjectSet
{
    public function getByTransport(string $transport): ?WorkerTransportThroughput
    {
        return $this->getByUniqueKey(WorkerTransportThroughput::uniqueKeyStatic($transport));
    }

    /**
     * The throughput of the transport; created with empty windows when the set has none.
     *
     * @param int[] $windowSeconds
     */
    public function getOrCreateByTransport(string $transport, array $windowSeconds = WorkerThroughputWindows::WINDOW_SECONDS): WorkerTransportThroughput
    {
        $workerTransportThroughput = $this->getByTransport($transport);
        if ($workerTransportThroughput === null) {
            $workerTransportThroughput = new WorkerTransportThroughput($transport, $windowSeconds);
            $this->add($workerTransportThroughput);
        }
        return $workerTransportThroughput;
    }
}
