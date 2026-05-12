<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Collection of trigger-level deltas for a single table. Held by {@see DBTableDiff::$triggerDiffs}.
 * Trigger bodies are compared after canonicalisation via
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaIntrospectionService::normaliseTriggerBody()} —
 * MODIFY is always implemented as DROP + CREATE since MySQL has no ALTER TRIGGER.
 *
 * @property DBTriggerDiff[] $elements
 * @method DBTriggerDiff getByUniqueKey(string $uniqueKey)
 * @method DBTriggerDiff[] getElements()
 * @method DBTriggerDiff first()
 */
class DBTriggerDiffs extends ObjectSet
{
    /**
     * Returns one independently-executable SQL statement per child diff. Each statement is either a
     * `DROP TRIGGER`, a `CREATE TRIGGER`, or the concatenation of both (for MODIFY).
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
