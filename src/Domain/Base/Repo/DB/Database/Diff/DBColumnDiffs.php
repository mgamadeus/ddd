<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Collection of column-level deltas for a single table. Held by {@see DBTableDiff::$columnDiffs}.
 * Element order is insertion order; the diff service preserves the order columns appear on the
 * target {@see \DDD\Domain\Base\Repo\DB\Database\DatabaseModel}, followed by DROP entries for live
 * columns not in target.
 *
 * @property DBColumnDiff[] $elements
 * @method DBColumnDiff getByUniqueKey(string $uniqueKey)
 * @method DBColumnDiff[] getElements()
 * @method DBColumnDiff first()
 */
class DBColumnDiffs extends ObjectSet
{
    /**
     * @return string[] One SQL statement per child diff. Statements already include the leading
     * `ALTER TABLE …` clause, so they are safely executable in isolation.
     */
    public function getSqlStatements(): array
    {
        $statements = [];
        foreach ($this->getElements() as $diff) {
            if ($diff->sql !== '') {
                $statements[] = $diff->sql;
            }
        }
        return $statements;
    }
}
