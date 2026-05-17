<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Content-keyed set of {@see DBCanonicalIndex} snapshots — keyed by `matchKey`
 * (`indexType + '|' + indexColumns`), not by `indexName`. The diff service pairs target-side and
 * live-side indexes by semantic identity rather than auto-generated name, so this set lets both
 * sides share the same lookup helper (`getByMatchKey`) without a re-key step.
 *
 * @property DBCanonicalIndex[] $elements
 * @method DBCanonicalIndex getByUniqueKey(string $uniqueKey)
 * @method DBCanonicalIndex first()
 * @method DBCanonicalIndex[] getElements()
 */
class DBCanonicalIndexes extends ObjectSet
{
    public function getByMatchKey(string $matchKey): ?DBCanonicalIndex
    {
        return $this->getByUniqueKey(DBCanonicalIndex::uniqueKeyStatic($matchKey));
    }
}
