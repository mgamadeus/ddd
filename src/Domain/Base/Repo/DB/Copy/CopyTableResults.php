<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Copy;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Per-table results of one {@see CopyToPostgresService} phase, in copy order.
 *
 * @property CopyTableResult[] $elements
 * @method CopyTableResult|null first()
 * @method CopyTableResult[] getElements()
 * @method CopyTableResult|null getByUniqueKey(string $uniqueKey)
 */
class CopyTableResults extends ObjectSet
{
}
