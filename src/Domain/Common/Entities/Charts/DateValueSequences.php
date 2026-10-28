<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Charts;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Several {@see DateValueSequence}s — e.g. a stacked day chart where each sequence is one stack key (a reason, a
 * world, a source).
 *
 * @property DateValueSequence[] $elements
 * @method DateValueSequence first()
 * @method DateValueSequence|null getByUniqueKey(string $uniqueKey)
 * @method DateValueSequence[] getElements()
 */
class DateValueSequences extends ObjectSet
{
}
