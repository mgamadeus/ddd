<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Reflection\ClassWithNamespace;

/**
 * Per-table aggregate describing the structural delta between the code-derived schema (target)
 * and the live database (current).
 *
 * Built by {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService::computeDiffs()}. Returned
 * inside a {@see DBTableDiffs} set; one instance per table that differs.
 *
 * For CREATE_TABLE the `sql` field carries the full CREATE statement produced by
 * {@see \DDD\Domain\Base\Repo\DB\Database\DatabaseModel::getSql()}. For ALTER_TABLE it is the
 * concatenation of all child diffs' SQL. For DROP_TABLE it is a single DROP TABLE statement.
 */
class DBTableDiff extends ValueObject
{
    public const string CHANGE_TYPE_CREATE_TABLE = 'CREATE_TABLE';

    public const string CHANGE_TYPE_DROP_TABLE = 'DROP_TABLE';

    public const string CHANGE_TYPE_ALTER_TABLE = 'ALTER_TABLE';

    public const string CHANGE_TYPE_NO_CHANGE = 'NO_CHANGE';

    public const string SEVERITY_ADDITIVE = 'ADDITIVE';

    public const string SEVERITY_DESTRUCTIVE = 'DESTRUCTIVE';

    public const string SEVERITY_MIXED = 'MIXED';

    /** @var string Physical SQL table name. */
    public string $sqlTableName;

    /**
     * @var ClassWithNamespace|null Source entity class. Null when the table exists only in the
     * live database (DROP_TABLE).
     */
    public ?ClassWithNamespace $entityClassWithNamespace = null;

    /** @var string One of CHANGE_TYPE_*. */
    public string $changeType = self::CHANGE_TYPE_NO_CHANGE;

    /** @var string One of SEVERITY_*. */
    public string $severity = self::SEVERITY_ADDITIVE;

    /** @var DBColumnDiffs Column-level deltas (ADD/DROP/MODIFY). */
    public DBColumnDiffs $columnDiffs;

    /** @var DBVirtualColumnDiffs Virtual-column-level deltas. */
    public DBVirtualColumnDiffs $virtualColumnDiffs;

    /** @var DBIndexDiffs Index-level deltas. */
    public DBIndexDiffs $indexDiffs;

    /** @var DBForeignKeyDiffs Foreign-key-level deltas. */
    public DBForeignKeyDiffs $foreignKeyDiffs;

    /** @var DBTriggerDiffs Trigger-level deltas (DROP+CREATE on body change). */
    public DBTriggerDiffs $triggerDiffs;

    /**
     * @var array|null Collation change descriptor: ['from' => …, 'to' => …]. Null if collation
     * matches between target and current.
     */
    public ?array $collationChange = null;

    /**
     * @var string[] Individual SQL statements that, executed in order, bring the live table to the
     * target shape. Each statement is independently executable (already wrapped in ALTER TABLE …
     * where required). Useful for granular per-operation apply in the UI.
     */
    public array $sqlStatements = [];

    /** @var string Concatenated SQL — convenience field, equal to implode(";\n", sqlStatements) + ";". */
    public string $sql = '';

    /**
     * Initialises the five child diff sets so consumers can always append without a null check
     * (e.g. `$tableDiff->columnDiffs->add(...)` directly after `new DBTableDiff()`).
     */
    public function __construct()
    {
        parent::__construct();
        $this->columnDiffs = new DBColumnDiffs();
        $this->virtualColumnDiffs = new DBVirtualColumnDiffs();
        $this->indexDiffs = new DBIndexDiffs();
        $this->foreignKeyDiffs = new DBForeignKeyDiffs();
        $this->triggerDiffs = new DBTriggerDiffs();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->sqlTableName);
    }

    /**
     * True when all child diff sets are empty and no collation change exists. The diff service
     * filters NO_CHANGE entries out of its result, so this is rarely seen externally — kept for
     * defensive use by callers that build diffs manually.
     */
    public function isEmpty(): bool
    {
        return $this->columnDiffs->isEmpty()
            && $this->virtualColumnDiffs->isEmpty()
            && $this->indexDiffs->isEmpty()
            && $this->foreignKeyDiffs->isEmpty()
            && $this->triggerDiffs->isEmpty()
            && $this->collationChange === null;
    }
}
