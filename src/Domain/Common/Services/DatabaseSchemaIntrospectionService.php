<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalColumn;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalForeignKey;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalIndex;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalTable;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalTrigger;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use Doctrine\DBAL\Exception;

/**
 * Reads the live database schema via INFORMATION_SCHEMA and returns canonical, strongly-typed
 * value objects shaped to match the target side built from
 * {@see \DDD\Domain\Base\Repo\DB\Database\DatabaseModel}.
 *
 * Both sides must produce the same VO shapes so {@see DatabaseSchemaDiffService} can compare them
 * property-by-property without further translation. The typed VOs catch field-drift bugs at parse
 * time (adding a property on one side and forgetting the other would silently emit MODIFYs
 * forever).
 *
 * Per-request cache: each table is introspected at most once per process.
 */
class DatabaseSchemaIntrospectionService
{
    /** @var string[]|null */
    protected ?array $liveTableNamesCache = null;

    /** @var array<string, ?DBCanonicalTable> Map of tableName → snapshot (or null when absent). */
    protected array $tableCache = [];

    /**
     * @return string[] Names of all base tables in the current database.
     * @throws Exception
     */
    public function getLiveTableNames(): array
    {
        if ($this->liveTableNamesCache !== null) {
            return $this->liveTableNamesCache;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $rows = $connection->fetchAllAssociative(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        );
        $names = [];
        foreach ($rows as $row) {
            $names[] = (string)$row['TABLE_NAME'];
        }
        $this->liveTableNamesCache = $names;
        return $names;
    }

    /**
     * Returns the canonical introspection of a single table, or null when the table is absent.
     *
     * @throws Exception
     */
    public function introspectTable(string $tableName): ?DBCanonicalTable
    {
        if (array_key_exists($tableName, $this->tableCache)) {
            return $this->tableCache[$tableName];
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $tableRow = $connection->fetchAssociative(
            "SELECT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND TABLE_TYPE = 'BASE TABLE'",
            ['table' => $tableName]
        );
        if (!$tableRow) {
            return $this->tableCache[$tableName] = null;
        }

        $table = new DBCanonicalTable();
        $table->tableName = $tableName;
        $table->collation = (string)$tableRow['TABLE_COLLATION'];

        // MariaDB stores JSON columns as LONGTEXT plus a CHECK (json_valid(col)) constraint
        // (JSON is an alias for LONGTEXT, not a distinct storage engine). To collapse this to
        // the same canonical sqlType the target side emits ('JSON'), fetch the json_valid
        // check-clauses up front and pass the column-name set into the column builder.
        $jsonValidColumns = $this->fetchJsonValidColumnNames($tableName);

        foreach ($this->fetchColumnRows($tableName) as $row) {
            $column = $this->buildCanonicalColumn($row, $jsonValidColumns);
            if ($column->isGenerated) {
                $table->virtualColumns[$column->name] = $column;
            } else {
                $table->columns[$column->name] = $column;
            }
        }

        $table->indexes = $this->fetchIndexes($tableName);
        $table->foreignKeys = $this->fetchForeignKeys($tableName);
        $table->triggers = $this->fetchTriggers($tableName);

        return $this->tableCache[$tableName] = $table;
    }

    /**
     * Clears the per-request cache. Call after applying a diff so the next computeDiffs() sees
     * the new live state.
     */
    public function invalidateCache(): void
    {
        $this->liveTableNamesCache = null;
        $this->tableCache = [];
    }

    /**
     * Canonicalises a MySQL generation expression so it can be compared to a DDD-side $as string.
     * MySQL stores the expression already parsed and lowercased with backtick-quoted identifiers
     * (e.g. `ifnull(\`tableNumber\`,0)`). DDD writes it as the original PHP source (e.g.
     * `(IFNULL(tableNumber, 0))`). Both go through this function before comparison.
     *
     * Iterative — extend as new patterns hit production. Current rules:
     *   - lowercase
     *   - strip backticks
     *   - strip surrounding parentheses (one outer pair only)
     *   - collapse runs of whitespace to single spaces
     *   - remove spaces adjacent to ( ) , so `ifnull (a , 0)` == `ifnull(a,0)`
     */
    public function normaliseGenerationExpression(?string $expression): ?string
    {
        if ($expression === null) {
            return null;
        }
        $value = strtolower($expression);
        $value = str_replace('`', '', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = trim($value);
        // strip one outer parens pair if and only if it wraps the entire expression
        if (strlen($value) >= 2 && $value[0] === '(' && $value[-1] === ')' && $this->parensBalanceWithoutOuter($value)) {
            $value = substr($value, 1, -1);
            $value = trim($value);
        }
        $value = preg_replace('/\s*([\(\),])\s*/', '$1', $value) ?? $value;
        return $value;
    }

    /**
     * Tells {@see self::normaliseGenerationExpression()} whether the outer parens wrap the entire
     * expression. Returns true only when the parens depth never returns to zero before the end.
     * `(a + b)` → true (safe to strip the outer pair). `(a) + (b)` → false (stripping the outer
     * pair would change semantics). Pure depth scan, no tokenising — adequate because we only ever
     * pass it canonicalised expressions where quotes have already been stripped.
     */
    protected function parensBalanceWithoutOuter(string $expr): bool
    {
        $depth = 0;
        $len = strlen($expr);
        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0 && $i < $len - 1) {
                    // closed before reaching end → outer pair does not wrap the whole expression
                    return false;
                }
            }
        }
        return $depth === 0;
    }

    /**
     * @return array<int,array<string,mixed>>
     * @throws Exception
     */
    protected function fetchColumnRows(string $tableName): array
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        return $connection->fetchAllAssociative(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                    CHARACTER_MAXIMUM_LENGTH, EXTRA, GENERATION_EXPRESSION
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
             ORDER BY ORDINAL_POSITION',
            ['table' => $tableName]
        );
    }

    /**
     * Returns the set of columns on a table that carry a `json_valid(`col`)` CHECK constraint.
     * MariaDB writes one such constraint per `JSON`-declared column (since `JSON` is an alias for
     * `LONGTEXT` plus a check), so this set is what tells us a LONGTEXT-typed column is
     * semantically a JSON column. MySQL 5.7+ has a native JSON type and does not emit json_valid
     * checks; on MySQL this returns an empty set and the LONGTEXT/JSON normalisation no-ops.
     *
     * INFORMATION_SCHEMA.CHECK_CONSTRAINTS is portable across MySQL 8.0.16+ and MariaDB 10.2+;
     * any error (older versions, restricted privileges) is caught and the result degrades to an
     * empty set rather than crashing the diff.
     *
     * @return array<string,true> Map of columnName => true.
     */
    protected function fetchJsonValidColumnNames(string $tableName): array
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        try {
            $rows = $connection->fetchAllAssociative(
                'SELECT CHECK_CLAUSE FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                ['table' => $tableName]
            );
        } catch (Exception) {
            return [];
        }
        $columns = [];
        foreach ($rows as $row) {
            // Examples:
            //   MariaDB: `json_valid(`name`)`
            //   MySQL:   `json_valid(`name`)` (when present at all)
            // Match the column name out of `json_valid(\`col\`)`. The check is case-insensitive
            // because MariaDB lower-cases function names in stored CHECK_CLAUSE.
            if (preg_match('/\bjson_valid\(\s*`?([^`)\s]+)`?\s*\)/i', (string)$row['CHECK_CLAUSE'], $matches)) {
                $columns[$matches[1]] = true;
            }
        }
        return $columns;
    }

    /**
     * Builds a canonical column VO (regular OR virtual — virtual is distinguished by
     * isGenerated=true). The result is type-equivalent to the target-side build in
     * {@see DatabaseSchemaDiffService::canonicaliseTargetColumn} — adding a field on either side
     * without updating the other now fails at parse time.
     *
     * @param array<string,mixed> $row Raw INFORMATION_SCHEMA.COLUMNS row.
     * @param array<string,true> $jsonValidColumns Set of column names with a json_valid() check.
     *        Used to normalise MariaDB's LONGTEXT-backed JSON columns to canonical sqlType 'JSON'.
     */
    protected function buildCanonicalColumn(array $row, array $jsonValidColumns = []): DBCanonicalColumn
    {
        $dataType = strtoupper((string)$row['DATA_TYPE']);
        $columnType = (string)$row['COLUMN_TYPE'];
        $extra = strtoupper((string)$row['EXTRA']);
        $generationExpression = $row['GENERATION_EXPRESSION'] !== '' && $row['GENERATION_EXPRESSION'] !== null
            ? (string)$row['GENERATION_EXPRESSION']
            : null;

        $isUnsigned = stripos($columnType, 'unsigned') !== false;
        $hasAutoIncrement = str_contains($extra, 'AUTO_INCREMENT');
        $isGenerated = $generationExpression !== null;
        // MySQL EXTRA contains "VIRTUAL GENERATED" or "STORED GENERATED" for generated columns.
        $isStored = $isGenerated ? str_contains($extra, 'STORED') : null;
        // DEFAULT_GENERATED appears in EXTRA when the default is an SQL expression rather than a
        // literal (e.g. DEFAULT CURRENT_TIMESTAMP, DEFAULT (UUID()), DEFAULT (JSON_OBJECT())). The
        // DDD entity layer cannot express expression defaults, so we mark the column and the diff
        // comparator will skip the default-value check rather than mass-emit MODIFYs that strip
        // the expression default in production.
        $defaultIsExpression = str_contains($extra, 'DEFAULT_GENERATED') && !$isGenerated;

        // CHARACTER_MAXIMUM_LENGTH is populated for VARCHAR/CHAR/TEXT/BLOB. We only care about
        // VARCHAR/CHAR — the others have implicit per-type maxima that the target side does not
        // track, so comparing would always be false-positive.
        $length = null;
        $vectorDimensions = null;
        $sqlType = $dataType;
        $varcharTypes = ['VARCHAR', 'CHAR'];
        if (in_array($dataType, $varcharTypes, true) && $row['CHARACTER_MAXIMUM_LENGTH'] !== null) {
            $length = (int)$row['CHARACTER_MAXIMUM_LENGTH'];
        } elseif ($dataType === 'VECTOR' && preg_match('/\((\d+)\)/', $columnType, $matches)) {
            $vectorDimensions = (int)$matches[1];
        } elseif ($dataType === 'TINYINT' && preg_match('/\((\d+)\)/', $columnType, $matches) && (int)$matches[1] === 1) {
            // MySQL stores BOOLEAN as TINYINT(1). DDD writes BOOLEAN.
            $sqlType = DatabaseColumn::SQL_TYPE_BOOL;
        } elseif ($dataType === 'LONGTEXT' && isset($jsonValidColumns[(string)$row['COLUMN_NAME']])) {
            // MariaDB implements `JSON` as `LONGTEXT` with a CHECK (json_valid(col)) constraint —
            // `JSON` is just an alias. INFORMATION_SCHEMA returns data_type='longtext' for these
            // columns. DDD's target generator emits 'JSON' for ValueObject-shaped properties, so
            // without normalisation every JSON column on MariaDB falsely reports a sqlType MODIFY
            // forever. Collapse to canonical 'JSON' here.
            $sqlType = DatabaseColumn::SQL_TYPE_JSON;
        }

        // Default value normalisation:
        // - MySQL 8+ returns NULL (PHP null) for both `DEFAULT NULL` and "no default at all".
        // - MariaDB returns the LITERAL STRING "NULL" for columns declared `DEFAULT NULL` (which
        //   DDD writes explicitly for every nullable column). Without normalisation, target
        //   (PHP null) would never equal live ("NULL" string) → every nullable column would be
        //   falsely reported as a MODIFY. Normalise the string back to null here so both DBMS
        //   converge on the same canonical form.
        $defaultValue = $row['COLUMN_DEFAULT'];
        if ($defaultValue !== null) {
            $defaultValue = (string)$defaultValue;
            if (strtoupper($defaultValue) === 'NULL') {
                $defaultValue = null;
            }
        }

        $column = new DBCanonicalColumn();
        $column->name = (string)$row['COLUMN_NAME'];
        $column->sqlType = $sqlType;
        $column->length = $length;
        $column->vectorDimensions = $vectorDimensions;
        $column->allowsNull = ((string)$row['IS_NULLABLE']) === 'YES';
        $column->isUnsigned = $isUnsigned;
        $column->hasAutoIncrement = $hasAutoIncrement;
        $column->defaultValue = $defaultValue;
        $column->defaultIsExpression = $defaultIsExpression;
        $column->isGenerated = $isGenerated;
        $column->generationExpression = $generationExpression;
        $column->isStored = $isStored;
        return $column;
    }

    /**
     * @return array<string, DBCanonicalIndex> Keyed by live INDEX_NAME (PRIMARY skipped).
     * @throws Exception
     */
    protected function fetchIndexes(string $tableName): array
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $rows = $connection->fetchAllAssociative(
            'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE, INDEX_TYPE
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            ['table' => $tableName]
        );

        $grouped = [];
        foreach ($rows as $row) {
            $indexName = (string)$row['INDEX_NAME'];
            if ($indexName === 'PRIMARY') {
                continue; // PK is part of CREATE TABLE, not the diff scope.
            }
            $indexType = $this->mapIndexType((string)$row['INDEX_TYPE'], (int)$row['NON_UNIQUE']);
            if (!isset($grouped[$indexName])) {
                $index = new DBCanonicalIndex();
                $index->indexName = $indexName;
                $index->indexType = $indexType;
                $index->indexColumns = [];
                // matchKey is populated after the columns are known, below.
                $grouped[$indexName] = $index;
            }
            $grouped[$indexName]->indexColumns[] = (string)$row['COLUMN_NAME'];
        }
        // Finalize matchKey now that all columns are gathered.
        foreach ($grouped as $index) {
            $index->matchKey = $this->buildIndexMatchKey($index->indexType, $index->indexColumns);
        }
        return $grouped;
    }

    /**
     * Index match key — kept in sync with {@see DatabaseSchemaDiffService::buildIndexMatchKey}.
     * Centralised here so both introspection and diff share one definition.
     *
     * @param string[] $indexColumns
     */
    public function buildIndexMatchKey(string $indexType, array $indexColumns): string
    {
        return $indexType . '|' . implode(',', $indexColumns);
    }

    /**
     * Maps MySQL INDEX_TYPE + NON_UNIQUE to DatabaseIndex::TYPE_* constants.
     *
     * VECTOR indexes are MariaDB-specific. MariaDB exposes them as INDEX_TYPE='VECTOR' in
     * recent versions; older versions may surface them as BTREE. We map best-effort and surface
     * MIXED severity if the diff service detects a mismatch.
     */
    protected function mapIndexType(string $mysqlIndexType, int $nonUnique): string
    {
        $upper = strtoupper($mysqlIndexType);
        return match ($upper) {
            'FULLTEXT' => DatabaseIndex::TYPE_FULLTEXT,
            'SPATIAL'  => DatabaseIndex::TYPE_SPATIAL,
            'VECTOR'   => DatabaseIndex::TYPE_VECTOR,
            default    => $nonUnique === 0 ? DatabaseIndex::TYPE_UNIQUE : DatabaseIndex::TYPE_INDEX,
        };
    }

    /**
     * @return array<string, DBCanonicalForeignKey> Keyed by live CONSTRAINT_NAME.
     * @throws Exception
     */
    protected function fetchForeignKeys(string $tableName): array
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $rows = $connection->fetchAllAssociative(
            'SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                    rc.UPDATE_RULE, rc.DELETE_RULE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
              AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
             WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.TABLE_NAME = :table
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL',
            ['table' => $tableName]
        );

        $foreignKeys = [];
        foreach ($rows as $row) {
            $constraintName = (string)$row['CONSTRAINT_NAME'];
            $fk = new DBCanonicalForeignKey();
            $fk->constraintName = $constraintName;
            $fk->internalIdColumn = (string)$row['COLUMN_NAME'];
            $fk->foreignTable = (string)$row['REFERENCED_TABLE_NAME'];
            $fk->foreignIdColumn = (string)$row['REFERENCED_COLUMN_NAME'];
            $fk->onUpdateAction = $this->mapReferentialAction((string)$row['UPDATE_RULE']);
            $fk->onDeleteAction = $this->mapReferentialAction((string)$row['DELETE_RULE']);
            $fk->matchKey = $this->buildForeignKeyMatchKey(
                $fk->internalIdColumn,
                $fk->foreignTable,
                $fk->foreignIdColumn
            );
            $foreignKeys[$constraintName] = $fk;
        }
        return $foreignKeys;
    }

    /**
     * FK match key — kept in sync with {@see DatabaseSchemaDiffService::buildForeignKeyMatchKey}.
     */
    public function buildForeignKeyMatchKey(
        string $internalIdColumn,
        string $foreignTable,
        string $foreignIdColumn
    ): string {
        return "$internalIdColumn->$foreignTable.$foreignIdColumn";
    }

    /**
     * @return array<string, DBCanonicalTrigger> Keyed by trigger name.
     * @throws Exception
     */
    protected function fetchTriggers(string $tableName): array
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $rows = $connection->fetchAllAssociative(
            'SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
             FROM INFORMATION_SCHEMA.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = :table
             ORDER BY TRIGGER_NAME',
            ['table' => $tableName]
        );

        $triggers = [];
        foreach ($rows as $row) {
            $triggerName = (string)$row['TRIGGER_NAME'];
            $actionStatement = (string)$row['ACTION_STATEMENT'];
            $trigger = new DBCanonicalTrigger();
            $trigger->triggerName = $triggerName;
            $trigger->tableName = $tableName;
            $trigger->timing = strtoupper((string)$row['ACTION_TIMING']);
            $trigger->event = strtoupper((string)$row['EVENT_MANIPULATION']);
            $trigger->actionStatement = $actionStatement;
            $trigger->normalisedBody = $this->normaliseTriggerBody($actionStatement);
            $triggers[$triggerName] = $trigger;
        }
        return $triggers;
    }

    /**
     * Canonicalises a trigger body so a target-side body (extracted from a .sql file) can be
     * compared to a live-side body (read from INFORMATION_SCHEMA.TRIGGERS.ACTION_STATEMENT).
     *
     * Rules — minimal on purpose, extend iteratively as production cases hit:
     *   - lowercase
     *   - collapse all whitespace runs to a single space
     *   - trim
     *   - strip backticks (live side uses them around identifiers, files may or may not)
     *   - strip a leading "BEGIN" and trailing "END" (live ACTION_STATEMENT does not include them
     *     for single-statement bodies; file SQL usually does — both shapes normalise to the same)
     */
    public function normaliseTriggerBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $value = strtolower($body);
        $value = str_replace('`', '', $value);
        $value = (string)preg_replace('/\s+/', ' ', $value);
        $value = trim($value);
        // Strip outermost BEGIN ... END if present — single-statement triggers don't need it.
        if (preg_match('/^begin\s+(.*?)\s+end\s*;?\s*$/s', $value, $matches)) {
            $value = trim($matches[1]);
        }
        // Trim trailing semicolons that exist on one side but not the other.
        return rtrim($value, "; \t\n\r\0\x0B");
    }

    /**
     * Normalises MySQL's referential action strings to {@see DatabaseForeignKey::ACTION_*} values.
     */
    protected function mapReferentialAction(string $mysqlAction): string
    {
        $upper = strtoupper($mysqlAction);
        return match ($upper) {
            'CASCADE'     => DatabaseForeignKey::ACTION_CASCADE,
            'SET NULL'    => DatabaseForeignKey::ACTION_SET_NULL,
            'RESTRICT'    => DatabaseForeignKey::ACTION_RESTRICT,
            'SET DEFAULT' => DatabaseForeignKey::ACTION_SET_DEFAULT,
            default       => DatabaseForeignKey::ACTION_NO_ACTION,
        };
    }
}
