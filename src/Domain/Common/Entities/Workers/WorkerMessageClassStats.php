<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property WorkerMessageClassStat[] $elements
 * @method WorkerMessageClassStat[] getElements()
 * @method WorkerMessageClassStat|null getByUniqueKey(string $uniqueKey)
 */
class WorkerMessageClassStats extends ObjectSet
{
}
