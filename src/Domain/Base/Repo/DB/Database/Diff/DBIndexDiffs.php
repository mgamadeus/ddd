<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property DBIndexDiff[] $elements
 * @method DBIndexDiff getByUniqueKey(string $uniqueKey)
 * @method DBIndexDiff[] getElements()
 * @method DBIndexDiff first()
 */
class DBIndexDiffs extends ObjectSet
{
    /**
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
