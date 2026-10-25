<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Schema;

use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use DDD\Domain\Base\Repo\DB\Database\FullTextMode;
use DDD\Domain\Base\Repo\DB\Database\VectorIndexProfile;
use DDD\Domain\Base\Repo\DB\Dialect\Identifiers\IdentifierPolicy;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * PostgreSQL CREATE-path renderer (dual-dialect architecture plan
 * `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §4.10/§6 row 4a). Covers exactly what step 4a
 * scopes: columns + types (Mig §3.1), identities, the primary key, unique/plain indexes (incl. `nullsNotDistinct`,
 * G-R3-2), foreign keys, `STORED` generated columns (the one case portable before `VirtualExpr`, step 7), trigram
 * and `to_tsvector` expression GIN indexes (D14, Mig §3.5), bool defaults, geometry (PostGIS, SRID 0, D5/Mig §3.8)
 * and vector (pgvector/pgvectorscale, D6) columns and indexes.
 *
 * **Out of scope here (step 7, "rest of PostgresDdlRenderer"):** ALTER/DROP/MODIFY, `NOT VALID` + `VALIDATE`,
 * per-column `CHECK` (`#[DatabaseCheck]`, G-R3-1), the trailing-space UNIQUE-key `CHECK`, `VirtualExpr` for any
 * virtual column OTHER than the one Translatable fulltext search column, per-dialect `DatabaseTrigger` bodies,
 * token-list `TOKEN_MATCH` indexes. Each unimplemented case throws {@see InternalErrorException} rather than
 * emitting silently-wrong SQL — a caller hitting one of these on Dineful's real entity set (152 tables, the next
 * build step) gets a clear, specific refusal instead of a table that type-checks but is wrong.
 *
 * **Cross-table ordering.** `renderCreate()` returns one model's statements in a safe standalone order (table, then
 * its indexes, then its foreign keys). Rendering a WHOLE entity set additionally needs every table created before
 * any foreign key that references it — entity-class scan order gives no such guarantee (a referenced table can sort
 * after its referrer). The console-command caller therefore collects every model's statements across the whole set
 * and runs them through {@see DdlStatement::sortByPhase()} once, so every `PHASE_CREATE_TABLE` statement executes
 * before any `PHASE_CREATE_FOREIGN_KEY` statement, system-wide.
 */
final class PostgresDdlRenderer implements DdlRenderer
{
    /** Mig §3.4: every varchar/text column gets this ICU nondeterministic collation unless overridden. */
    public const string DEFAULT_COLLATION = 'ddd_ci_ai';

    /** `DatabaseColumn::COLLATION_UTF8MB4_BIN` maps to PostgreSQL's deterministic, byte-exact "C" collation. */
    public const string BINARY_COLLATION = 'C';

    public function __construct(private readonly IdentifierPolicy $identifierPolicy = new IdentifierPolicy())
    {
    }

    // ───────────────────────────────────────────────────────── ensure()

    public function ensure(): array
    {
        $statements = [];

        // vectorscale is ON_DISK's (default, D6) diskann; postgis backs geometry columns; pg_trgm + unaccent back
        // NAME-mode fulltext (ddd_fold); pg_stat_statements is operational (query-plan visibility), not read by the
        // renderer, but declared here so one ensure() call is the complete, idempotent infra surface (Arch §4.11).
        foreach (['pg_trgm', 'unaccent', 'postgis', 'vector', 'vectorscale', 'pg_stat_statements'] as $extension) {
            $statements[] = new DdlStatement(
                sql: "CREATE EXTENSION IF NOT EXISTS \"$extension\"",
                phase: DdlStatement::PHASE_EXTENSION,
            );
        }

        $statements[] = new DdlStatement(
            sql: 'CREATE COLLATION IF NOT EXISTS "' . self::DEFAULT_COLLATION
                . '" (provider = icu, locale = \'und-u-ks-level1\', deterministic = false)',
            phase: DdlStatement::PHASE_COLLATION,
        );

        foreach ($this->ensureFunctionStatements() as $statement) {
            $statements[] = $statement;
        }

        return $statements;
    }

    /** @return DdlStatement[] */
    protected function ensureFunctionStatements(): array
    {
        // Every ddd_* function below pins `SET search_path = public, pg_catalog` (verified against a real PG 18
        // container 2026-10-25). Without it, a SQL-language function that calls another by its bare name — exactly
        // what ddd_fold(x) does calling ddd_unaccent(x) — resolves fine in an ordinary SELECT/EXPLAIN, but FAILS
        // with "function ddd_unaccent(text) does not exist … during inlining" the moment it is used inside a
        // `CREATE INDEX … (expr)` (any access method, any opclass, with or without an explicit COLLATE): index-DDL
        // re-validates the expression's immutability by re-inlining nested SQL-function bodies under a search path
        // that does not include the ambient session's path. Reproduced minimally: two trivial single-level
        // IMMUTABLE SQL functions, `outer_fn(x) AS $$ SELECT inner_fn(x) $$`, calling each other with NO unaccent/
        // regdictionary involved at all — same failure, same fix. Schema-qualifying every call site (`public.foo(…)`)
        // fixes it identically; `SET search_path` is used instead because it is one declaration per function instead
        // of one per call site, so it is not forgotten if a fifth ddd_* function is added referencing a fourth.

        // ddd_now(): Arch §3.6 "current time is statement time" — not referenced by any step-4a fixture column
        // default (none of the three use ChangeHistory), but declared now since every renderer-referenced ddd_*
        // function belongs in one place and this one is needed the moment a ChangeHistory-bearing entity is rendered.
        $nowFunction = <<<'SQL'
            CREATE OR REPLACE FUNCTION ddd_now() RETURNS timestamp(0) without time zone
                LANGUAGE sql STABLE PARALLEL SAFE
                SET search_path = public, pg_catalog
                AS $ddd$ SELECT date_trunc('second', statement_timestamp())::timestamp(0) $ddd$
            SQL;

        // The 2-arg unaccent(regdictionary, text) form is IMMUTABLE; the 1-arg form resolves its dictionary via
        // search_path and is STABLE, so it cannot back a generated column or an index expression (both require
        // IMMUTABLE). Wrapping the 2-arg form with a fixed dictionary name is the standard IMMUTABLE-unaccent trick.
        $unaccentFunction = <<<'SQL'
            CREATE OR REPLACE FUNCTION ddd_unaccent(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE
                SET search_path = public, pg_catalog
                AS $ddd$ SELECT unaccent('unaccent'::regdictionary, $1) $ddd$
            SQL;

        // ddd_fold(x) = lower(unaccent(x)) — Mig §3.5, the NAME-mode fulltext predicate and index expression.
        $foldFunction = <<<'SQL'
            CREATE OR REPLACE FUNCTION ddd_fold(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE
                SET search_path = public, pg_catalog
                AS $ddd$ SELECT lower(ddd_unaccent($1)) $ddd$
            SQL;

        // Ports the MariaDB virtualNameSearch REGEXP_REPLACE/JSON_UNQUOTE template (DatabaseModel::fromEntityClass())
        // for the ONE virtual-column shape step 4a supports: concatenate every non-empty value of a JSON object
        // with " | ", preserving document order via WITH ORDINALITY (the `json` type itself is order-preserving,
        // D4, but json_each_text()'s iteration order is an implementation detail, not a documented guarantee — the
        // explicit ordinality column removes that doubt). This is a SEARCH-ONLY column: unlike the main JSON
        // storage order rule, value order here has no product meaning, only "every language's value is searchable"
        // does, so exact byte-for-byte parity with MariaDB's output is not a goal. Calls only pg_catalog builtins
        // (json_each_text, string_agg, coalesce), so the search_path pin is defensive rather than load-bearing here.
        //
        // Real data (Radbonus copy, Mig blocker): a Translatable column can hold a JSON ARRAY instead of an
        // object (`ChatMessages.localizedBody`, 5 rows with `[]`) — `json_each_text()` requires an object and
        // raises "cannot deconstruct an array as an object" on anything else. `json_typeof` guards the shape:
        // non-object input (array, scalar, or SQL NULL — `json_typeof(NULL::json)` is NULL, falling to ELSE too)
        // renders '' instead. This is a deliberate PostgreSQL deviation from MariaDB, which renders the array's
        // JSON text verbatim (`'[]'` for that row) through its REGEXP_REPLACE template — searching over an empty
        // (or any) JSON array has no language-keyed text to extract, so '' is the correct "nothing to search"
        // answer, not a parity bug.
        $translatableTextFunction = <<<'SQL'
            CREATE OR REPLACE FUNCTION ddd_translatable_text(input json) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE
                SET search_path = public, pg_catalog
                AS $ddd$
                    SELECT CASE
                        WHEN json_typeof(input) = 'object' THEN (
                            SELECT COALESCE(string_agg(t.value, ' | ' ORDER BY t.ord), '')
                            FROM json_each_text(regexp_replace(input::text, '(?<!\\)\\u0000', '', 'g')::json) WITH ORDINALITY AS t(key, value, ord)
                            WHERE t.value IS NOT NULL AND t.value <> ''
                        )
                        ELSE ''
                    END
                $ddd$
            SQL;

        // ddd_json_merge_patch(): the write path's order-preserving JSON_MERGE_PATCH equivalent (dual-dialect
        // architecture plan §4.6 MERGE_PATCH, Mig §3.2). A FLAT (one JSON-object-level) merge — the only shape
        // any entity in this codebase stores through a mergable JSON column (Translatable language maps,
        // `TrackGeoHash`-style settings objects): nested recursive merging (MySQL JSON_MERGE_PATCH's general
        // contract) is NOT implemented, since nothing here needs it. `json_each(...)` (the `json`-typed variant,
        // not `json_each_text`) keeps each value's ORIGINAL text verbatim — no jsonb round trip, which would
        // reorder keys. Order rule: a target key kept (patch omits it, or patch's value is JSON `null` → DROPPED)
        // keeps its original position; a target key the patch overwrites (non-null) keeps its position but gets
        // the patch's value; a patch-only key (non-null) is appended, in the patch's own order, after every
        // target-derived row. `STRICT`: NULL in, NULL out (every call site here already does
        // `COALESCE(target,'{}')`/`COALESCE(patch,'{}')`, so NULL never actually reaches it — declared to match
        // the plan's "like JSON_MERGE_PATCH" wording).
        $jsonMergePatchFunction = <<<'SQL'
            CREATE OR REPLACE FUNCTION ddd_json_merge_patch(target json, patch json) RETURNS json
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                SET search_path = public, pg_catalog
                AS $ddd$
                    WITH t AS (
                        SELECT key, value, ord FROM json_each(target) WITH ORDINALITY AS x(key, value, ord)
                    ),
                    p AS (
                        SELECT key, value, ord FROM json_each(patch) WITH ORDINALITY AS x(key, value, ord)
                    ),
                    kept_or_patched AS (
                        SELECT t.key, COALESCE(p.value, t.value) AS value, t.ord
                        FROM t LEFT JOIN p ON p.key = t.key
                        WHERE p.key IS NULL OR p.value::text IS DISTINCT FROM 'null'
                    ),
                    appended AS (
                        SELECT p.key, p.value, 1000000 + p.ord AS ord
                        FROM p
                        WHERE NOT EXISTS (SELECT 1 FROM t WHERE t.key = p.key)
                          AND p.value::text IS DISTINCT FROM 'null'
                    )
                    SELECT COALESCE(
                        (
                            SELECT ('{' || string_agg(to_json(all_rows.key)::text || ': ' || all_rows.value::text, ', ' ORDER BY all_rows.ord) || '}')::json
                            FROM (SELECT * FROM kept_or_patched UNION ALL SELECT * FROM appended) all_rows
                        ),
                        '{}'::json
                    )
                $ddd$
            SQL;

        // Raw-SQL compatibility shim: app/test code that calls the MariaDB builtin VEC_ToText(col) directly
        // (outside DQL — DQL's own vector functions, step 5, are a separate registry) gets the same text-array
        // form on PostgreSQL. pgvector's own `vector::text` cast already renders "[v1,v2,...]", which is valid
        // JSON array text verbatim, so this is a one-line passthrough. Unquoted in SQL, the identifier folds to
        // lowercase ("vec_totext"), which PostgreSQL resolves case-insensitively against this declaration.
        $vecToTextFunction = <<<'SQL'
            CREATE OR REPLACE FUNCTION vec_totext(vector) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE
                AS $ddd$ SELECT $1::text $ddd$
            SQL;

        return array_map(
            static fn (string $sql): DdlStatement => new DdlStatement(sql: $sql, phase: DdlStatement::PHASE_FUNCTION),
            [$nowFunction, $unaccentFunction, $foldFunction, $translatableTextFunction, $jsonMergePatchFunction, $vecToTextFunction]
        );
    }

    // ───────────────────────────────────────────────────────── renderCreate()

    public function renderCreate(DatabaseModel $databaseModel): array
    {
        if ($databaseModel->parentEntityCLassWithNamespace) {
            // Single-table-inheritance subclass: the parent entity's table already carries every column.
            return [];
        }

        $table = $databaseModel->sqlTableName;
        $this->identifierPolicy->reserve($table);

        $columnDefinitions = [];
        foreach ($databaseModel->columns->getElements() as $column) {
            if ($column->ignoreProperty) {
                continue;
            }
            $columnDefinitions[] = $this->renderColumnDefinition($column);
        }
        foreach ($databaseModel->virtualColumns->getElements() as $virtualColumn) {
            $columnDefinitions[] = $this->renderVirtualColumnDefinition($virtualColumn, $table);
        }
        $primaryKey = $databaseModel->columns->getPrimaryKeyColumn();
        if ($primaryKey !== null) {
            $columnDefinitions[] = 'PRIMARY KEY ("' . $primaryKey->name . '")';
        }

        $statements = [
            new DdlStatement(
                sql: "CREATE TABLE \"$table\" (\n    " . implode(",\n    ", $columnDefinitions) . "\n)",
                phase: DdlStatement::PHASE_CREATE_TABLE,
                table: $table,
            ),
        ];

        foreach ($databaseModel->indexes->getElements() as $index) {
            if ($index->indexType === DatabaseIndex::TYPE_NONE) {
                continue;
            }
            $statements[] = new DdlStatement(
                sql: $this->renderIndex($databaseModel, $index),
                phase: DdlStatement::PHASE_CREATE_INDEX,
                table: $table,
            );
        }

        foreach ($databaseModel->foreignKeys->getElements() as $foreignKey) {
            if (!$foreignKey->applyForeignKeyConstraint) {
                continue;
            }
            $statements[] = new DdlStatement(
                sql: $this->renderForeignKey($table, $foreignKey),
                phase: DdlStatement::PHASE_CREATE_FOREIGN_KEY,
                table: $table,
            );
        }

        return $statements;
    }

    // ───────────────────────────────────────────────────────── columns

    protected function renderColumnDefinition(DatabaseColumn $column): string
    {
        $type = $this->mapColumnType($column);
        $collation = $this->collationClause($column->sqlType, $column->encrypted, $column->collation);
        $notNull = !$column->allowsNull ? ' NOT NULL' : '';
        $identity = ($column->isPrimaryKey && $column->hasAutoIncrement) ? ' GENERATED BY DEFAULT AS IDENTITY' : '';
        $default = $this->defaultClause($column);

        return '"' . $column->name . '" ' . $type . $collation . $notNull . $identity . $default;
    }

    /**
     * Every virtual/generated column PostgresDdlRenderer knows how to produce (dual-dialect architecture plan
     * §4.10, build step 7): the Translatable fulltext search column (its own MariaDB-only template, recognised by
     * flag, not by parsing `$as`); the explicit `asPostgres:` escape hatch; and every `$as` shape
     * {@see VirtualExpr::fromMariaDbExpression()} recognises (Mig §3.7: `IFNULL` coalesce, the three JSON-extract
     * forms). Anything else has no PostgreSQL form yet and is refused, naming the table, property and expression,
     * rather than silently emitting the MariaDB expression (invalid SQL on PostgreSQL) or an empty column.
     */
    protected function renderVirtualColumnDefinition(DatabaseVirtualColumn $virtualColumn, string $table): string
    {
        $columnName = $virtualColumn->getName();

        if ($virtualColumn->isTranslatableFullTextSearchColumn) {
            if ($virtualColumn->translatableBaseColumnName === null) {
                throw new InternalErrorException(
                    "PostgresDdlRenderer: \"$table\".\"$columnName\" is flagged as the Translatable fulltext search "
                    . 'column but carries no translatableBaseColumnName — this is a DatabaseModel bug, not a '
                    . 'missing VirtualExpr case.'
                );
            }
            $expression = 'ddd_translatable_text("' . $virtualColumn->translatableBaseColumnName . '")';
            // TEXT column; Mig §3.5 "every fulltext column and virtual{Prop}Search keeps its text type and
            // ddd_ci_ai" (equality/LIKE/ORDER BY run against it, not just the GIN index).
            $collation = ' COLLATE "' . self::DEFAULT_COLLATION . '"';
            // Arch §4.10: "generated columns are always STORED" — PostgreSQL requires STORED for a column
            // referenced by an index (the GIN fulltext index on this very column) regardless of
            // $virtualColumn->stored, so the flag is not read here (true for every branch below too).
            return '"' . $columnName . '" text' . $collation . ' GENERATED ALWAYS AS (' . $expression . ') STORED';
        }

        $propertyName = $virtualColumn->referenceColumn?->name ?? $columnName;

        if ($virtualColumn->asPostgres !== null) {
            [$type, $collation] = $this->columnDefinitionForReferenceColumn($virtualColumn);
            return '"' . $columnName . '" ' . $type . $collation . ' GENERATED ALWAYS AS (' . $virtualColumn->asPostgres . ') STORED';
        }

        $virtualExpr = VirtualExpr::fromMariaDbExpression($virtualColumn->as);
        if ($virtualExpr === null) {
            throw new InternalErrorException(
                "PostgresDdlRenderer: virtual column on \"$table\".\"$propertyName\" (rendered as \"$columnName\") "
                . 'has no PostgreSQL form — VirtualExpr does not recognise this MariaDB expression: '
                . $virtualColumn->as
            );
        }

        if ($virtualExpr->intent === VirtualExpr::INTENT_COALESCE) {
            [$type, $collation] = $this->columnDefinitionForReferenceColumn($virtualColumn);
        } else {
            $type = $virtualExpr->postgresColumnType();
            // Only jsonText produces a text-shaped result; jsonInt/jsonDecimal are numeric, never collated.
            $collation = $virtualExpr->intent === VirtualExpr::INTENT_JSON_TEXT
                ? ' COLLATE "' . self::DEFAULT_COLLATION . '"'
                : '';
        }

        return '"' . $columnName . '" ' . $type . $collation . ' GENERATED ALWAYS AS (' . $virtualExpr->renderPostgres() . ') STORED';
    }

    /**
     * `coalesce` and `asPostgres:` both carry over the type of the property's own reference column (a passthrough
     * of an existing id/string column, e.g. `IFNULL(locationId, 0)` is still an id, `bigint`/`integer`-shaped).
     *
     * @return array{0: string, 1: string} [type, collation clause]
     */
    protected function columnDefinitionForReferenceColumn(DatabaseVirtualColumn $virtualColumn): array
    {
        $referenceColumn = $virtualColumn->referenceColumn;
        if ($referenceColumn === null) {
            throw new InternalErrorException(
                'PostgresDdlRenderer: virtual column "' . $virtualColumn->getName() . '" has no referenceColumn to '
                . 'derive its PostgreSQL type from.'
            );
        }
        $type = $this->mapColumnType($referenceColumn);
        $collation = $this->collationClause($referenceColumn->sqlType, $referenceColumn->encrypted, $referenceColumn->collation);
        return [$type, $collation];
    }

    protected function mapColumnType(DatabaseColumn $column): string
    {
        if ($column->encrypted) {
            // Mirrors DatabaseColumn::getSqlType()'s encrypted branch: text-shaped types stay text, everything
            // else (including JSON, which is not persisted once encrypted) becomes varchar(255).
            return $this->isTextLike($column->sqlType) || $column->sqlType === DatabaseColumn::SQL_TYPE_JSON
                ? 'text'
                : 'varchar(255)';
        }

        return match ($column->sqlType) {
            DatabaseColumn::SQL_TYPE_INT => $column->isUnsigned ? 'bigint' : 'integer', // Mig §3.1: FK/unsigned INT -> bigint, plain INT -> integer
            DatabaseColumn::SQL_TYPE_BIGINT => 'bigint',
            DatabaseColumn::SQL_TYPE_FLOAT => 'real',
            DatabaseColumn::SQL_TYPE_DOUBLE => 'double precision',
            DatabaseColumn::SQL_TYPE_BOOL => 'boolean',
            DatabaseColumn::SQL_TYPE_VARCHAR => "varchar($column->varCharLength)",
            DatabaseColumn::SQL_TYPE_DATE => 'date',
            DatabaseColumn::SQL_TYPE_DATETIME => 'timestamp(0) without time zone',
            DatabaseColumn::SQL_TYPE_TEXT, DatabaseColumn::SQL_TYPE_MEDIUMTEXT, DatabaseColumn::SQL_TYPE_LONGTEXT => 'text',
            DatabaseColumn::SQL_TYPE_BLOB, DatabaseColumn::SQL_TYPE_MEDIUMBLOB, DatabaseColumn::SQL_TYPE_LONGBLOB => 'bytea',
            DatabaseColumn::SQL_TYPE_JSON => 'json', // D4: json (text), never jsonb, at the switch
            DatabaseColumn::SQL_TYPE_POINT => 'geometry(Point,0)', // D5/Mig §3.8: SRID 0 on every geometry column
            DatabaseColumn::SQL_TYPE_LINESTRING => 'geometry(LineString,0)',
            DatabaseColumn::SQL_TYPE_POLYGON => 'geometry(Polygon,0)',
            DatabaseColumn::SQL_TYPE_VECTOR => "vector($column->vectorDimensions)",
            default => throw new InternalErrorException(
                "PostgresDdlRenderer: no PostgreSQL column type mapping for MariaDB SQL type \"$column->sqlType\"."
            ),
        };
    }

    protected function collationClause(?string $sqlType, bool $encrypted, ?string $mariaDbCollation): string
    {
        if ($encrypted || !$this->isTextLike($sqlType)) {
            return '';
        }
        $collation = match ($mariaDbCollation) {
            null => self::DEFAULT_COLLATION,
            DatabaseColumn::COLLATION_UTF8MB4_BIN => self::BINARY_COLLATION,
            default => throw new InternalErrorException(
                "PostgresDdlRenderer: MariaDB collation \"$mariaDbCollation\" has no PostgreSQL mapping yet (step 7); "
                . 'only the default (ddd_ci_ai) and COLLATION_UTF8MB4_BIN ("C") are wired.'
            ),
        };
        return ' COLLATE "' . $collation . '"';
    }

    protected function isTextLike(?string $sqlType): bool
    {
        return in_array(
            $sqlType,
            [
                DatabaseColumn::SQL_TYPE_VARCHAR,
                DatabaseColumn::SQL_TYPE_TEXT,
                DatabaseColumn::SQL_TYPE_MEDIUMTEXT,
                DatabaseColumn::SQL_TYPE_LONGTEXT,
            ],
            true
        );
    }

    protected function defaultClause(DatabaseColumn $column): string
    {
        if ($column->sqlType === DatabaseColumn::SQL_TYPE_VECTOR) {
            // $column->sqlDefaultValue, if set, carries MariaDB's raw VEC_FromText(...) expression
            // (DatabaseColumn::createMariaDbNullVectorDefault()) — not valid SQL on PostgreSQL. D12 keeps the
            // zero-vector sentinel at the switch, so PostgreSQL gets its own literal instead of reusing that value.
            return ' DEFAULT ' . $this->postgresNullVectorLiteral($column->vectorDimensions ?? 0);
        }

        if (!isset($column->sqlDefaultValue)) {
            return '';
        }
        $value = $column->sqlDefaultValue;

        if ($column->sqlType === DatabaseColumn::SQL_TYPE_BOOL) {
            // createFromReflectionProperty() stores MariaDB's (int)true/false here, not a PHP bool.
            return ' DEFAULT ' . ((int)$value !== 0 ? 'TRUE' : 'FALSE');
        }
        if (is_bool($value)) {
            return ' DEFAULT ' . ($value ? 'TRUE' : 'FALSE');
        }
        if (is_string($value)) {
            return " DEFAULT '" . str_replace("'", "''", $value) . "'";
        }
        // int/float, or a DatabaseSqlExpression (raw SQL, __toString()s as-is) for any future non-vector case.
        return ' DEFAULT ' . $value;
    }

    protected function postgresNullVectorLiteral(int $dimensions): string
    {
        if ($dimensions <= 0) {
            throw new InternalErrorException('PostgresDdlRenderer: VECTOR dimensions must be a positive integer.');
        }
        return "'[" . implode(',', array_fill(0, $dimensions, '0')) . "]'::vector";
    }

    // ───────────────────────────────────────────────────────── indexes

    protected function renderIndex(DatabaseModel $databaseModel, DatabaseIndex $index): string
    {
        $table = $databaseModel->sqlTableName;
        $indexName = $this->identifierPolicy->indexName($table, $index->getIndexNameBase());

        return match ($index->indexType) {
            DatabaseIndex::TYPE_INDEX => $this->renderBtreeIndex($table, $indexName, $index, unique: false),
            DatabaseIndex::TYPE_UNIQUE => $this->renderBtreeIndex($table, $indexName, $index, unique: true),
            DatabaseIndex::TYPE_FULLTEXT => $this->renderFulltextIndex($table, $indexName, $index),
            DatabaseIndex::TYPE_SPATIAL => $this->renderSpatialIndex($table, $indexName, $index),
            DatabaseIndex::TYPE_VECTOR => $this->renderVectorIndex($databaseModel, $table, $indexName, $index),
            default => throw new InternalErrorException(
                "PostgresDdlRenderer: unknown index type \"$index->indexType\"."
            ),
        };
    }

    protected function renderBtreeIndex(string $table, string $indexName, DatabaseIndex $index, bool $unique): string
    {
        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
        // G-R3-2: NULLS NOT DISTINCT only ever applies to a UNIQUE index; a plain index ignores the flag.
        $nullsNotDistinct = ($unique && $index->nullsNotDistinct) ? ' NULLS NOT DISTINCT' : '';
        return "CREATE $kind \"$indexName\" ON \"$table\" (" . $this->quoteColumns($index->indexColumns) . ')' . $nullsNotDistinct;
    }

    protected function renderSpatialIndex(string $table, string $indexName, DatabaseIndex $index): string
    {
        // GiST is PostGIS's R-tree-equivalent access method (Mig §3.8) — MariaDB's SPATIAL INDEX counterpart.
        return "CREATE INDEX \"$indexName\" ON \"$table\" USING gist (" . $this->quoteColumns($index->indexColumns) . ')';
    }

    /**
     * D14/Mig §3.5: NAME = pg_trgm prefix search on ddd_fold(col); DOCUMENT = to_tsvector fulltext; D19/step 5
     * TOKEN_MATCH = GIN on `string_to_array(col, ' ')` for the Radbonus `Tracks.allocations` token-list shape —
     * the identical expression {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\TokenMatch} renders in its predicate,
     * or the planner ignores the index.
     */
    protected function renderFulltextIndex(string $table, string $indexName, DatabaseIndex $index): string
    {
        if (count($index->indexColumns) !== 1) {
            throw new InternalErrorException(
                'PostgresDdlRenderer: a multi-column FULLTEXT index is not yet portable (step 7).'
            );
        }
        $column = '"' . $index->indexColumns[0] . '"';

        return match ($index->fullTextMode) {
            FullTextMode::NAME => "CREATE INDEX \"$indexName\" ON \"$table\" USING gin ((ddd_fold($column) COLLATE \"C\") gin_trgm_ops)",
            FullTextMode::DOCUMENT => "CREATE INDEX \"$indexName\" ON \"$table\" USING gin (to_tsvector('simple', ddd_unaccent($column)))",
            FullTextMode::TOKEN_MATCH => "CREATE INDEX \"$indexName\" ON \"$table\" USING gin (string_to_array($column, ' '))",
        };
    }

    /** D6: ON_DISK (default) -> pgvectorscale diskann at any dimension; IN_MEMORY -> pgvector hnsw, halfvec cast 2001-4000 dims. */
    protected function renderVectorIndex(DatabaseModel $databaseModel, string $table, string $indexName, DatabaseIndex $index): string
    {
        if (count($index->indexColumns) !== 1) {
            throw new InternalErrorException('PostgresDdlRenderer: a multi-column VECTOR index is not supported.');
        }
        $columnName = $index->indexColumns[0];
        $column = $databaseModel->columns->getColumnByName($columnName);
        if ($column === null || $column->sqlType !== DatabaseColumn::SQL_TYPE_VECTOR) {
            throw new InternalErrorException(
                "PostgresDdlRenderer: VECTOR index on \"$table\".\"$columnName\" has no matching vector column."
            );
        }
        $dimensions = $column->vectorDimensions ?? 0;
        $opsSuffix = $index->distanceMetric === DatabaseIndex::DISTANCE_METRIC_EUCLIDEAN ? 'l2_ops' : 'cosine_ops';
        $profile = $index->vectorIndexProfile ?? VectorIndexProfile::ON_DISK;
        $quotedColumn = '"' . $columnName . '"';

        if ($profile === VectorIndexProfile::ON_DISK) {
            return "CREATE INDEX \"$indexName\" ON \"$table\" USING diskann ($quotedColumn vector_$opsSuffix)";
        }

        // IN_MEMORY: pgvector hnsw. halfvec cast kicks in for 2001-4000 dims; the query-side ORDER BY (step 5) casts
        // the same way so the index actually serves it (D6).
        if ($dimensions > 4000) {
            throw new InternalErrorException(
                "PostgresDdlRenderer: vector($dimensions) on \"$table\".\"$columnName\" exceeds hnsw's 4000-dim "
                . 'ceiling for vectorIndexProfile: IN_MEMORY — use ON_DISK instead (D6).'
            );
        }
        if ($dimensions > 2000) {
            return "CREATE INDEX \"$indexName\" ON \"$table\" USING hnsw (($quotedColumn::halfvec($dimensions)) halfvec_$opsSuffix)";
        }
        return "CREATE INDEX \"$indexName\" ON \"$table\" USING hnsw ($quotedColumn vector_$opsSuffix)";
    }

    protected function quoteColumns(array $columns): string
    {
        return implode(', ', array_map(static fn (string $column): string => '"' . $column . '"', $columns));
    }

    // ───────────────────────────────────────────────────────── foreign keys

    protected function renderForeignKey(string $table, DatabaseForeignKey $foreignKey): string
    {
        // D1/§4.5: table-first order (opposite of MariaDB's fk_{table}_{col}) so a schema-wide alphabetical listing
        // groups every constraint by its owning table.
        $constraintName = $this->identifierPolicy->foreignKeyName($table, $foreignKey->internalIdColumn);

        return "ALTER TABLE \"$table\" ADD CONSTRAINT \"$constraintName\" FOREIGN KEY (\"$foreignKey->internalIdColumn\") "
            . "REFERENCES \"$foreignKey->foreignTable\" (\"$foreignKey->foreignIdColumn\") "
            // DatabaseForeignKey::ACTION_* strings (CASCADE, NO ACTION, SET NULL, RESTRICT, SET DEFAULT) are already
            // valid PostgreSQL referential-action keywords — no per-dialect mapping needed.
            . "ON UPDATE $foreignKey->onUpdateAction ON DELETE $foreignKey->onDeleteAction";
    }
}
