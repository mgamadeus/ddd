<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Content-keyed set of generated (virtual / stored) {@see DBCanonicalColumn} snapshots. Distinct
 * from {@see DBCanonicalColumns} so a table's regular columns and generated columns can live in
 * separate bags on {@see DBCanonicalTable} — matching the framework precedent
 * (`DatabaseColumns` vs `DatabaseVirtualColumns` on `DatabaseModel`).
 *
 * Element type is the same `DBCanonicalColumn` VO; `isGenerated === true` for every member here.
 *
 * @property DBCanonicalColumn[] $elements
 * @method DBCanonicalColumn getByUniqueKey(string $uniqueKey)
 * @method DBCanonicalColumn first()
 * @method DBCanonicalColumn[] getElements()
 */
class DBCanonicalVirtualColumns extends ObjectSet
{
    public function getByColumnName(string $columnName): ?DBCanonicalColumn
    {
        return $this->getByUniqueKey(DBCanonicalColumn::uniqueKeyStatic($columnName));
    }
}
