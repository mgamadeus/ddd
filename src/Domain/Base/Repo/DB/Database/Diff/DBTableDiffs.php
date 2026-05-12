<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Collection of {@see DBTableDiff}. Mirrors {@see \DDD\Domain\Base\Repo\DB\Database\DatabaseModels}
 * in shape — flat ObjectSet indexed by SQL table name for O(1) lookup.
 *
 * Returned by {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService::computeDiffs()} and
 * consumed by both the apply path and the admin endpoint.
 *
 * @property DBTableDiff[] $elements
 * @method DBTableDiff getByUniqueKey(string $uniqueKey)
 * @method DBTableDiff[] getElements()
 * @method DBTableDiff first()
 */
class DBTableDiffs extends ObjectSet
{
    /**
     * @var DBTableDiff[] Indexed by sqlTableName for getByTableName() O(1) lookups.
     */
    protected array $diffsByTableName = [];

    /**
     * Overrides parent `add()` to additionally index incoming {@see DBTableDiff} elements by their
     * `sqlTableName` so {@see self::getDiffByTableName()} is O(1). Non-{@see DBTableDiff} elements
     * are still added to the parent set but not indexed (defensive — should not happen in practice).
     *
     * Signature matches parent's `ObjectSet::add(?BaseObject &...$elements)`.
     */
    public function add(?BaseObject &...$elements): void
    {
        foreach ($elements as $element) {
            if ($element instanceof DBTableDiff) {
                $this->diffsByTableName[$element->sqlTableName] = $element;
            }
        }
        parent::add(...$elements);
    }

    /**
     * O(1) lookup by physical SQL table name. Returns null when no diff for that table is present
     * — typically because the table is in sync (filtered out as `NO_CHANGE` before this set is
     * returned) or the table is ignored.
     */
    public function getDiffByTableName(string $sqlTableName): ?DBTableDiff
    {
        return $this->diffsByTableName[$sqlTableName] ?? null;
    }

    /**
     * Returns a new set containing only diffs matching the given change type.
     * @param string $changeType One of DBTableDiff::CHANGE_TYPE_*.
     */
    public function getByChangeType(string $changeType): DBTableDiffs
    {
        $filtered = new self();
        foreach ($this->getElements() as $diff) {
            if ($diff->changeType === $changeType) {
                $filtered->add($diff);
            }
        }
        return $filtered;
    }

    /**
     * Returns a new set containing only diffs matching the given severity.
     * @param string $severity One of DBTableDiff::SEVERITY_*.
     */
    public function getBySeverity(string $severity): DBTableDiffs
    {
        $filtered = new self();
        foreach ($this->getElements() as $diff) {
            if ($diff->severity === $severity) {
                $filtered->add($diff);
            }
        }
        return $filtered;
    }

    /**
     * Concatenated SQL for every table diff in the set. Order matches insertion order
     * (table-by-table as produced by the diff service).
     */
    public function getSql(): string
    {
        $parts = [];
        foreach ($this->getElements() as $diff) {
            if ($diff->sql !== '') {
                $parts[] = $diff->sql;
            }
        }
        return implode("\n\n", $parts);
    }
}
