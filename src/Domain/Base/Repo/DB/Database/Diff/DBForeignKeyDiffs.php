<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property DBForeignKeyDiff[] $elements
 * @method DBForeignKeyDiff getByUniqueKey(string $uniqueKey)
 * @method DBForeignKeyDiff[] getElements()
 * @method DBForeignKeyDiff first()
 */
class DBForeignKeyDiffs extends ObjectSet
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
