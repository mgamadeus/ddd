<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Ordered list of {@see DoctrineBoundSql}; execution follows insertion order.
 *
 * @property DoctrineBoundSql[] $elements
 * @method DoctrineBoundSql first()
 * @method DoctrineBoundSql[] getElements()
 */
class DoctrineBoundSqls extends ObjectSet
{
}
