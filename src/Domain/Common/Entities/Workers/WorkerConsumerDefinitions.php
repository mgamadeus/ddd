<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property WorkerConsumerDefinition[] $elements
 * @method WorkerConsumerDefinition[] getElements()
 * @method WorkerConsumerDefinition|null getByUniqueKey(string $uniqueKey)
 */
class WorkerConsumerDefinitions extends ObjectSet
{
    public function getByTransport(string $transport): ?WorkerConsumerDefinition
    {
        return $this->getByUniqueKey(WorkerConsumerDefinition::uniqueKeyStatic($transport));
    }

    /** @return string[] */
    public function getTransportNames(): array
    {
        return array_values(array_map(
            static fn(WorkerConsumerDefinition $workerConsumerDefinition): string => $workerConsumerDefinition->transport,
            $this->getElements()
        ));
    }
}
