<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property WorkerQueueCount[] $elements
 * @method WorkerQueueCount[] getElements()
 * @method WorkerQueueCount|null getByUniqueKey(string $uniqueKey)
 */
class WorkerQueueCounts extends ObjectSet
{
    public function getByTransport(string $transport): ?WorkerQueueCount
    {
        return $this->getByUniqueKey(WorkerQueueCount::uniqueKeyStatic($transport));
    }

    public function addCount(string $transport, string $state, ?int $waiting = null, ?string $error = null): void
    {
        $workerQueueCount = new WorkerQueueCount($transport, $state, $waiting, $error);
        $this->add($workerQueueCount);
    }
}
