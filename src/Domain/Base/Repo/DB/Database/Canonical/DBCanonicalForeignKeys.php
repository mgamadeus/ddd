<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Content-keyed set of {@see DBCanonicalForeignKey} snapshots — keyed by `matchKey`
 * (`internalIdColumn->foreignTable.foreignIdColumn`), not by `constraintName`. The diff service
 * pairs target-side and live-side FKs by semantic identity rather than auto-generated constraint
 * name, so this set lets both sides share the same lookup helper (`getByMatchKey`) without a
 * re-key step.
 *
 * @property DBCanonicalForeignKey[] $elements
 * @method DBCanonicalForeignKey getByUniqueKey(string $uniqueKey)
 * @method DBCanonicalForeignKey first()
 * @method DBCanonicalForeignKey[] getElements()
 */
class DBCanonicalForeignKeys extends ObjectSet
{
    public function getByMatchKey(string $matchKey): ?DBCanonicalForeignKey
    {
        return $this->getByUniqueKey(DBCanonicalForeignKey::uniqueKeyStatic($matchKey));
    }
}
