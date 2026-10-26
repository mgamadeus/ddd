<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A contiguous run of a batch's rows that one statement can write ({@see BatchPlanner}); `$rowByRow` forces one
 * statement per row (two rows of the run collide on the same conflict key, or a row needs the nullable fallback).
 */
class BatchRun extends ValueObject
{
    /** @var int[] The batch positions of the run's rows, in input order */
    public array $modelIndexes = [];

    /** @var bool Whether the run is written one statement per row */
    public bool $rowByRow = false;

    /** Every instance is its own element: equal values may legitimately repeat, and order matters. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
