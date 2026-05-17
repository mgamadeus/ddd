<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Collection of {@see DBExpectedDiffSignature}. Passed to
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService::applyDiffs()} to gate the apply
 * against view-vs-execute drift. Null means "skip the signature gate" (CLI/messenger only — the
 * admin frontend always sends a fully-populated set covering every diff in the batch).
 *
 * @property DBExpectedDiffSignature[] $elements
 * @method DBExpectedDiffSignature getByUniqueKey(string $uniqueKey)
 * @method DBExpectedDiffSignature first()
 * @method DBExpectedDiffSignature[] getElements()
 */
class DBExpectedDiffSignatures extends ObjectSet
{
    /**
     * Returns the signature for the named table, or null when no pair is carried. O(1) lookup via
     * the inherited unique-key index — {@see DBExpectedDiffSignature::uniqueKey()} is keyed on
     * `sqlTableName`. Used by the service's strict-cover check and the per-diff comparison in
     * `assertDiffSignaturesMatch`.
     */
    public function getSignatureByTableName(string $sqlTableName): ?string
    {
        return $this->getByUniqueKey(DBExpectedDiffSignature::uniqueKeyStatic($sqlTableName))?->signature;
    }

    /** @return string[] List of sqlTableNames covered by this set, in insertion order. */
    public function getCoveredTableNames(): array
    {
        $out = [];
        foreach ($this->elements as $entry) {
            $out[] = $entry->sqlTableName;
        }
        return $out;
    }

    /**
     * Returns a new set containing only the pairs whose sqlTableName is in `$sqlTableNames`. Used
     * by the controller to narrow a fully-captured frontend set down to a partial scope. Follows
     * the framework's `getByX` collection-filter convention (cf. `DBTableDiffs::getByChangeType`,
     * `getBySeverity`, `DatabaseColumns::getColumnByName`).
     *
     * @param string[] $sqlTableNames
     */
    public function getByTableNames(array $sqlTableNames): self
    {
        $allowed = array_flip($sqlTableNames);
        $narrowed = new self();
        foreach ($this->elements as $entry) {
            if (isset($allowed[$entry->sqlTableName])) {
                $narrowed->add($entry);
            }
        }
        return $narrowed;
    }

    /**
     * Builds a set from a flat string→string map. Tolerates the empty case (returns an empty set);
     * callers that want "null = bypass gate" semantics must handle that one level up.
     *
     * @param array<string,string> $map
     */
    public static function fromMap(array $map): self
    {
        $set = new self();
        foreach ($map as $sqlTableName => $signature) {
            $entry = new DBExpectedDiffSignature();
            $entry->sqlTableName = (string)$sqlTableName;
            $entry->signature = (string)$signature;
            $set->add($entry);
        }
        return $set;
    }
}
