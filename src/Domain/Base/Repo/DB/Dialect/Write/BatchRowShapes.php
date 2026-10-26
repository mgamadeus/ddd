<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The {@see BatchRowShape}s of a batch, in input order: position N is the batch's model N.
 *
 * @property BatchRowShape[] $elements
 * @method BatchRowShape|null first()
 * @method BatchRowShape[] getElements()
 */
class BatchRowShapes extends ObjectSet
{
}
