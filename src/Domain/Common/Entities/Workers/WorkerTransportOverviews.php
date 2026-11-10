<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property WorkerTransportOverview[] $elements
 * @method WorkerTransportOverview[] getElements()
 * @method WorkerTransportOverview|null getByUniqueKey(string $uniqueKey)
 */
class WorkerTransportOverviews extends ObjectSet
{
    public function getByTransport(string $transport): ?WorkerTransportOverview
    {
        return $this->getByUniqueKey(WorkerTransportOverview::uniqueKeyStatic($transport));
    }
}
