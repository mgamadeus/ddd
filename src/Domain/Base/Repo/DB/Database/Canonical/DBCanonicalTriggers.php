<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Content-keyed set of {@see DBCanonicalTrigger} snapshots — keyed by the trigger's
 * `tableName.triggerName` composite (see {@see DBCanonicalTrigger::uniqueKey()}). Trigger names
 * are unique per schema in MySQL/MariaDB, but the table prefix keeps the key stable when a set
 * carries triggers from multiple tables (rare today, useful tomorrow).
 *
 * @property DBCanonicalTrigger[] $elements
 * @method DBCanonicalTrigger getByUniqueKey(string $uniqueKey)
 * @method DBCanonicalTrigger first()
 * @method DBCanonicalTrigger[] getElements()
 */
class DBCanonicalTriggers extends ObjectSet
{
    public function getByTriggerName(string $tableName, string $triggerName): ?DBCanonicalTrigger
    {
        return $this->getByUniqueKey(DBCanonicalTrigger::uniqueKeyStatic($tableName . '.' . $triggerName));
    }
}
