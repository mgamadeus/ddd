<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property WorkerLoopDefinition[] $elements
 * @method WorkerLoopDefinition[] getElements()
 * @method WorkerLoopDefinition|null getByUniqueKey(string $uniqueKey)
 */
class WorkerLoopDefinitions extends ObjectSet
{
}
