<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Aggregate canonical snapshot of one table. Returned by
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaIntrospectionService::introspectTable()} as the
 * "current" side, and built ad-hoc by {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService}
 * for the "target" side (using DatabaseModel as source).
 */
class DBCanonicalTable extends ValueObject
{
    public string $tableName;

    public string $collation;

    /** @var array<string, DBCanonicalColumn> Regular columns keyed by column name. */
    public array $columns = [];

    /** @var array<string, DBCanonicalColumn> Generated/virtual columns keyed by column name. */
    public array $virtualColumns = [];

    /** @var array<string, DBCanonicalIndex> Live side: keyed by live INDEX_NAME. Target side: keyed by matchKey. */
    public array $indexes = [];

    /** @var array<string, DBCanonicalForeignKey> Live side: keyed by CONSTRAINT_NAME. Target side: keyed by matchKey. */
    public array $foreignKeys = [];

    /** @var array<string, DBCanonicalTrigger> Keyed by trigger name. */
    public array $triggers = [];

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->tableName);
    }
}
