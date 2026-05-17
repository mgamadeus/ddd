<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Content-keyed set of regular {@see DBCanonicalColumn} snapshots. Used on both sides of the
 * schema diff: the live side is built by
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaIntrospectionService::introspectTable()} from
 * INFORMATION_SCHEMA, the target side is built by
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService::canonicaliseTargetColumns()} from
 * a {@see \DDD\Domain\Base\Repo\DB\Database\DatabaseModel}.
 *
 * Unique-keyed by column name (`DBCanonicalColumn::uniqueKey()` returns `name`), so per-column
 * presence checks during diff (`getByColumnName`) are O(1).
 *
 * @property DBCanonicalColumn[] $elements
 * @method DBCanonicalColumn getByUniqueKey(string $uniqueKey)
 * @method DBCanonicalColumn first()
 * @method DBCanonicalColumn[] getElements()
 */
class DBCanonicalColumns extends ObjectSet
{
    public function getByColumnName(string $columnName): ?DBCanonicalColumn
    {
        return $this->getByUniqueKey(DBCanonicalColumn::uniqueKeyStatic($columnName));
    }
}
