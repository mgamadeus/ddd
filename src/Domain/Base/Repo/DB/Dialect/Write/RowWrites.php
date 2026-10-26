<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The {@see RowWrite}s of a batch, in input order: position N is the batch's model N.
 *
 * @property RowWrite[] $elements
 * @method RowWrite|null first()
 * @method RowWrite[] getElements()
 */
class RowWrites extends ObjectSet
{
}
