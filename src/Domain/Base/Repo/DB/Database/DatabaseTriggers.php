<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @method DatabaseModel getParent()
 * @property DatabaseTrigger[] $elements;
 * @method DatabaseTrigger getByUniqueKey(string $uniqueKey)
 * @method DatabaseTrigger first()
 * @method DatabaseTrigger[] getElements()
 */
class DatabaseTriggers extends ObjectSet
{
}