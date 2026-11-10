<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property WorkerTransportHistory[] $elements
 * @method WorkerTransportHistory[] getElements()
 * @method WorkerTransportHistory|null getByUniqueKey(string $uniqueKey)
 */
class WorkerTransportHistories extends ObjectSet
{
    public function getByTransport(string $transport): ?WorkerTransportHistory
    {
        return $this->getByUniqueKey(WorkerTransportHistory::uniqueKeyStatic($transport));
    }

    /** The history of the transport; created empty when the set has none */
    public function getOrCreateByTransport(string $transport): WorkerTransportHistory
    {
        $workerTransportHistory = $this->getByTransport($transport);
        if ($workerTransportHistory === null) {
            $workerTransportHistory = new WorkerTransportHistory($transport);
            $this->add($workerTransportHistory);
        }
        return $workerTransportHistory;
    }
}
