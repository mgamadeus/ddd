<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Table snapshots keyed by table name (see {@see DBCanonicalTable::uniqueKey()}).
 *
 * @property DBCanonicalTable[] $elements
 * @method DBCanonicalTable getByUniqueKey(string $uniqueKey)
 * @method DBCanonicalTable first()
 * @method DBCanonicalTable[] getElements()
 */
class DBCanonicalTables extends ObjectSet
{
    public function getByTableName(string $tableName): ?DBCanonicalTable
    {
        return $this->getByUniqueKey(DBCanonicalTable::uniqueKeyStatic($tableName));
    }
}
