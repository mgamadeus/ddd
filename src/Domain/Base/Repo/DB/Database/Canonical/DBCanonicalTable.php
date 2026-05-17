<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Aggregate canonical snapshot of one table. Returned by
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaIntrospectionService::introspectTable()} as the
 * "current" side, and built ad-hoc by {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService}
 * for the "target" side (using DatabaseModel as source).
 *
 * Every child collection is a typed `ObjectSet` rather than a raw array. Index / FK sets are keyed
 * by `matchKey` (semantic identity) on both sides — see
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaIntrospectionService::buildIndexMatchKey()} and
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaIntrospectionService::buildForeignKeyMatchKey()}.
 * The auto-generated live names are still carried per-element (`indexName`, `constraintName`) so
 * DROP statements can target the real DB-side identifiers.
 */
class DBCanonicalTable extends ValueObject
{
    public string $tableName;

    public string $collation;

    /** @var DBCanonicalColumns Regular columns, keyed by column name. */
    public DBCanonicalColumns $columns;

    /** @var DBCanonicalVirtualColumns Generated/virtual columns, keyed by column name. */
    public DBCanonicalVirtualColumns $virtualColumns;

    /** @var DBCanonicalIndexes Indexes, keyed by matchKey on both sides. */
    public DBCanonicalIndexes $indexes;

    /** @var DBCanonicalForeignKeys Foreign keys, keyed by matchKey on both sides. */
    public DBCanonicalForeignKeys $foreignKeys;

    /** @var DBCanonicalTriggers Triggers, keyed by `tableName.triggerName`. */
    public DBCanonicalTriggers $triggers;

    /**
     * Initialises every child collection so consumers (introspection + diff service) can call
     * `$table->columns->add(...)` immediately after `new DBCanonicalTable()` without a null check.
     */
    public function __construct()
    {
        parent::__construct();
        $this->columns = new DBCanonicalColumns();
        $this->virtualColumns = new DBCanonicalVirtualColumns();
        $this->indexes = new DBCanonicalIndexes();
        $this->foreignKeys = new DBCanonicalForeignKeys();
        $this->triggers = new DBCanonicalTriggers();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->tableName);
    }
}
