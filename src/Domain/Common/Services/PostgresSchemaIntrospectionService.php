<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalColumn;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalColumns;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalForeignKey;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalForeignKeys;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalIndex;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalIndexes;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalTable;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalTrigger;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalTriggers;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use Doctrine\DBAL\Exception;

/**
 * PostgreSQL side of {@see DatabaseSchemaIntrospectionService}: reads one schema from `pg_catalog` and returns the same
 * canonical value objects. Types are PostgreSQL's own rendering (`format_type()`), defaults and generation expressions
 * `pg_get_expr()`, index definitions `pg_get_indexdef()`, trigger bodies the trigger function's source — so the diff
 * compares two PostgreSQL schemas normalised by PostgreSQL itself: the live schema and a scratch schema the diff builds
 * from the entities ({@see DatabaseSchemaDiffService}).
 *
 * Schema qualifiers are stripped from every expression and definition (the scratch schema's name, the introspected
 * schema's name and `public`), so the same object compares equal whichever schema it lives in.
 */
class PostgresSchemaIntrospectionService extends DatabaseSchemaIntrospectionService
{
    /** @var string|null The schema to introspect; null = the connection's current_schema() */
    protected ?string $schemaName;

    public function __construct(?string $schemaName = null)
    {
        $this->schemaName = $schemaName;
    }

    /**
     * @throws Exception
     */
    public function getSchemaName(): string
    {
        if ($this->schemaName === null) {
            $this->schemaName = (string)EntityManagerFactory::getInstance()->getConnection()->fetchOne('SELECT current_schema()');
        }
        return $this->schemaName;
    }

    /**
     * @return string[] Names of all ordinary and partitioned tables in the schema.
     * @throws Exception
     */
    public function getLiveTableNames(): array
    {
        if ($this->liveTableNamesCache !== null) {
            return $this->liveTableNamesCache;
        }
        $rows = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = :schema AND c.relkind IN ('r', 'p')
             ORDER BY c.relname",
            ['schema' => $this->getSchemaName()]
        );
        return $this->liveTableNamesCache = array_map('strval', $rows);
    }

    /**
     * @throws Exception
     */
    public function introspectTable(string $tableName): ?DBCanonicalTable
    {
        if (array_key_exists($tableName, $this->tableCache)) {
            return $this->tableCache[$tableName];
        }
        if (!in_array($tableName, $this->getLiveTableNames(), true)) {
            return $this->tableCache[$tableName] = null;
        }

        $table = new DBCanonicalTable();
        $table->tableName = $tableName;
        // PostgreSQL has no table collation; collations live on the columns
        $table->collation = '';
        foreach ($this->fetchPostgresColumns($tableName) as $column) {
            if ($column->isGenerated) {
                $table->virtualColumns->add($column);
            } else {
                $table->columns->add($column);
            }
        }
        $table->indexes = $this->fetchIndexes($tableName);
        $table->foreignKeys = $this->fetchForeignKeys($tableName);
        $table->triggers = $this->fetchTriggers($tableName);
        return $this->tableCache[$tableName] = $table;
    }

    /**
     * Removes schema qualifiers (`"schema".` / `schema.`) of the introspected schema and of `public` from an
     * expression or definition, so the same object compares equal across schemas.
     */
    public function stripSchemaQualifiers(string $sql): string
    {
        foreach (array_unique([$this->getSchemaName(), 'public']) as $schemaName) {
            $sql = str_replace(['"' . $schemaName . '".', $schemaName . '.'], '', $sql);
        }
        return $sql;
    }

    /**
     * @throws Exception
     */
    protected function fetchPostgresColumns(string $tableName): DBCanonicalColumns
    {
        $rows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            "SELECT a.attname,
                    format_type(a.atttypid, a.atttypmod) AS column_type,
                    a.attnotnull,
                    a.attidentity,
                    a.attgenerated,
                    pg_get_expr(d.adbin, d.adrelid) AS default_expression,
                    CASE WHEN a.attcollation <> 0 AND a.attcollation <> t.typcollation THEN column_collation_entry.collname END AS column_collation
             FROM pg_attribute a
             JOIN pg_class c ON c.oid = a.attrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_type t ON t.oid = a.atttypid
             LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             LEFT JOIN pg_collation column_collation_entry ON column_collation_entry.oid = a.attcollation
             WHERE n.nspname = :schema AND c.relname = :table AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY a.attnum",
            ['schema' => $this->getSchemaName(), 'table' => $tableName]
        );
        $columns = new DBCanonicalColumns();
        foreach ($rows as $row) {
            $isGenerated = (string)$row['attgenerated'] !== '';
            $expression = $row['default_expression'] !== null
                ? $this->stripSchemaQualifiers((string)$row['default_expression'])
                : null;
            $column = new DBCanonicalColumn();
            $column->name = (string)$row['attname'];
            $column->sqlType = $this->stripSchemaQualifiers((string)$row['column_type']);
            $column->allowsNull = !$this->isTrue($row['attnotnull']);
            $column->hasAutoIncrement = (string)$row['attidentity'] !== '';
            $column->isGenerated = $isGenerated;
            $column->isStored = $isGenerated ? true : null;
            $column->generationExpression = $isGenerated ? $expression : null;
            $column->defaultValue = $isGenerated ? null : $expression;
            $column->collation = $row['column_collation'] !== null ? (string)$row['column_collation'] : null;
            $columns->add($column);
        }
        return $columns;
    }

    /**
     * Secondary indexes (the primary key is skipped). The match key is the index definition without the index name
     * and the schema qualifiers — two indexes with the same definition are the same index, whatever they are called.
     * {@see DBCanonicalIndex::$definitionSql} carries the definition to re-create the index.
     *
     * @throws Exception
     */
    protected function fetchIndexes(string $tableName): DBCanonicalIndexes
    {
        $rows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            "SELECT index_class.relname AS index_name,
                    i.indisunique,
                    access_method.amname,
                    pg_get_indexdef(i.indexrelid) AS index_definition,
                    (SELECT string_agg(a.attname, ',' ORDER BY k.ordinality)
                     FROM unnest(i.indkey) WITH ORDINALITY AS k(attnum, ordinality)
                     JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum) AS index_columns
             FROM pg_index i
             JOIN pg_class index_class ON index_class.oid = i.indexrelid
             JOIN pg_class table_class ON table_class.oid = i.indrelid
             JOIN pg_namespace n ON n.oid = table_class.relnamespace
             JOIN pg_am access_method ON access_method.oid = index_class.relam
             WHERE n.nspname = :schema AND table_class.relname = :table AND NOT i.indisprimary
             ORDER BY index_class.relname",
            ['schema' => $this->getSchemaName(), 'table' => $tableName]
        );
        $indexes = new DBCanonicalIndexes();
        foreach ($rows as $row) {
            $definition = $this->stripSchemaQualifiers((string)$row['index_definition']);
            $index = new DBCanonicalIndex();
            $index->indexName = (string)$row['index_name'];
            $index->indexType = $this->mapPostgresIndexType((string)$row['amname'], $this->isTrue($row['indisunique']));
            $index->indexColumns = $row['index_columns'] !== null && $row['index_columns'] !== ''
                ? explode(',', (string)$row['index_columns'])
                : [];
            $index->definitionSql = $definition;
            $index->matchKey = $this->buildDefinitionMatchKey($definition);
            $indexes->add($index);
        }
        return $indexes;
    }

    /**
     * `CREATE [UNIQUE] INDEX <name> ON [ONLY] <table> USING …` without the index name: the identity of an index.
     */
    public function buildDefinitionMatchKey(string $indexDefinition): string
    {
        return (string)preg_replace('/^CREATE (UNIQUE )?INDEX \S+ ON (ONLY )?/i', 'CREATE $1INDEX ON ', $indexDefinition);
    }

    protected function mapPostgresIndexType(string $accessMethod, bool $isUnique): string
    {
        if ($isUnique) {
            return DatabaseIndex::TYPE_UNIQUE;
        }
        return match ($accessMethod) {
            'gin' => DatabaseIndex::TYPE_FULLTEXT,
            'gist' => DatabaseIndex::TYPE_SPATIAL,
            'hnsw', 'ivfflat', 'diskann' => DatabaseIndex::TYPE_VECTOR,
            default => DatabaseIndex::TYPE_INDEX,
        };
    }

    /**
     * Single-column foreign keys (the shape {@see DatabaseForeignKey} declares), keyed like the MariaDB side.
     *
     * @throws Exception
     */
    protected function fetchForeignKeys(string $tableName): DBCanonicalForeignKeys
    {
        $rows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            "SELECT constraint_entry.conname,
                    local_attribute.attname AS column_name,
                    foreign_class.relname AS foreign_table,
                    foreign_attribute.attname AS foreign_column,
                    constraint_entry.confupdtype,
                    constraint_entry.confdeltype
             FROM pg_constraint constraint_entry
             JOIN pg_class table_class ON table_class.oid = constraint_entry.conrelid
             JOIN pg_namespace n ON n.oid = table_class.relnamespace
             JOIN pg_class foreign_class ON foreign_class.oid = constraint_entry.confrelid
             JOIN pg_attribute local_attribute
               ON local_attribute.attrelid = constraint_entry.conrelid AND local_attribute.attnum = constraint_entry.conkey[1]
             JOIN pg_attribute foreign_attribute
               ON foreign_attribute.attrelid = constraint_entry.confrelid AND foreign_attribute.attnum = constraint_entry.confkey[1]
             WHERE n.nspname = :schema AND table_class.relname = :table AND constraint_entry.contype = 'f'
             ORDER BY constraint_entry.conname",
            ['schema' => $this->getSchemaName(), 'table' => $tableName]
        );
        $foreignKeys = new DBCanonicalForeignKeys();
        foreach ($rows as $row) {
            $foreignKey = new DBCanonicalForeignKey();
            $foreignKey->constraintName = (string)$row['conname'];
            $foreignKey->internalIdColumn = (string)$row['column_name'];
            $foreignKey->foreignTable = (string)$row['foreign_table'];
            $foreignKey->foreignIdColumn = (string)$row['foreign_column'];
            $foreignKey->onUpdateAction = $this->mapPostgresReferentialAction((string)$row['confupdtype']);
            $foreignKey->onDeleteAction = $this->mapPostgresReferentialAction((string)$row['confdeltype']);
            $foreignKey->matchKey = $this->buildForeignKeyMatchKey(
                $foreignKey->internalIdColumn,
                $foreignKey->foreignTable,
                $foreignKey->foreignIdColumn
            );
            $foreignKeys->add($foreignKey);
        }
        return $foreignKeys;
    }

    protected function mapPostgresReferentialAction(string $actionCode): string
    {
        return match ($actionCode) {
            'c' => DatabaseForeignKey::ACTION_CASCADE,
            'n' => DatabaseForeignKey::ACTION_SET_NULL,
            'r' => DatabaseForeignKey::ACTION_RESTRICT,
            'd' => DatabaseForeignKey::ACTION_SET_DEFAULT,
            default => DatabaseForeignKey::ACTION_NO_ACTION,
        };
    }

    /**
     * User triggers of the table. The body to compare is the timing/event definition of the trigger (without its name
     * and table) plus the source of its function — a changed function body or a changed trigger event is a change.
     *
     * @throws Exception
     */
    protected function fetchTriggers(string $tableName): DBCanonicalTriggers
    {
        $rows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            "SELECT trigger_entry.tgname,
                    pg_get_triggerdef(trigger_entry.oid) AS trigger_definition,
                    trigger_function.prosrc AS function_source
             FROM pg_trigger trigger_entry
             JOIN pg_class table_class ON table_class.oid = trigger_entry.tgrelid
             JOIN pg_namespace n ON n.oid = table_class.relnamespace
             JOIN pg_proc trigger_function ON trigger_function.oid = trigger_entry.tgfoid
             WHERE n.nspname = :schema AND table_class.relname = :table AND NOT trigger_entry.tgisinternal
             ORDER BY trigger_entry.tgname",
            ['schema' => $this->getSchemaName(), 'table' => $tableName]
        );
        $triggers = new DBCanonicalTriggers();
        foreach ($rows as $row) {
            $definition = $this->stripSchemaQualifiers((string)$row['trigger_definition']);
            $trigger = new DBCanonicalTrigger();
            $trigger->triggerName = (string)$row['tgname'];
            $trigger->tableName = $tableName;
            if (preg_match('/\s(BEFORE|AFTER|INSTEAD OF)\s+(.+?)\s+ON\s/i', $definition, $timingMatch)) {
                $trigger->timing = strtoupper($timingMatch[1]);
                $trigger->event = strtoupper($timingMatch[2]);
            }
            $trigger->actionStatement = (string)$row['function_source'];
            $definitionWithoutName = (string)preg_replace('/^CREATE (CONSTRAINT )?TRIGGER \S+ /i', '', $definition);
            $trigger->normalisedBody = $this->normaliseTriggerBody(
                $definitionWithoutName . ' ' . $this->stripSchemaQualifiers((string)$row['function_source'])
            );
            $triggers->add($trigger);
        }
        return $triggers;
    }

    protected function isTrue(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }
}
