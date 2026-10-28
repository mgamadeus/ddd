<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Charts;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Several {@see DateTimeValueSequence}s — e.g. one line per environment or a stacked sub-day chart.
 *
 * @property DateTimeValueSequence[] $elements
 * @method DateTimeValueSequence first()
 * @method DateTimeValueSequence|null getByUniqueKey(string $uniqueKey)
 * @method DateTimeValueSequence[] getElements()
 */
class DateTimeValueSequences extends ObjectSet
{
}
