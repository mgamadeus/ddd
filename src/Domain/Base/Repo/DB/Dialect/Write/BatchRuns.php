<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The {@see BatchRun}s of a batch, in input order.
 *
 * @property BatchRun[] $elements
 * @method BatchRun|null first()
 * @method BatchRun[] getElements()
 */
class BatchRuns extends ObjectSet
{
}
