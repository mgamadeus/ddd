<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services\Fixtures;

use DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRow;

/** A subclass that adds a column to its parent's table */
class ExcludedTableSubclassRow extends ChangeHistoryRow
{
    public ?string $addedColumn = null;
}
