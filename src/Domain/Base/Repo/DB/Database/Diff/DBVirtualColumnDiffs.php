<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Collection of virtual (generated) column deltas for a single table. Held by
 * {@see DBTableDiff::$virtualColumnDiffs}. MODIFY is always implemented as DROP + ADD because MySQL
 * forbids ALTER on the generation expression of a virtual column.
 *
 * @property DBVirtualColumnDiff[] $elements
 * @method DBVirtualColumnDiff getByUniqueKey(string $uniqueKey)
 * @method DBVirtualColumnDiff[] getElements()
 * @method DBVirtualColumnDiff first()
 */
class DBVirtualColumnDiffs extends ObjectSet
{
    /**
     * Returns one independently-executable SQL statement per child diff. For MODIFY this is the
     * concatenated `DROP COLUMN … ; ADD COLUMN …` pair.
     *
     * @return string[]
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
