<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Copy;

use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatement;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\DBAL\Connection;
use PDO;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The engine behind `app:db:copy-to-postgres` (`agentic-plans/backend/2026-10-22-postgres-migration.md` §5.1/§5.5):
 * copies one MariaDB database into PostgreSQL using the dual-dialect generator's own DDL, phase by phase. The
 * console command ({@see \DDD\Symfony\Commands\Base\Database\CopyToPostgres}) stays a thin shell — connection
 * resolution, option parsing, the allow-pattern/dialect refusals — and delegates every phase to this class so it
 * can be exercised directly from a test without a CommandTester.
 *
 * **Spike scope** (not yet built — see the class's own phase methods for exactly where): no Mode B
 * (snapshot-first, reconciliation-based copy for a table whose Mode-A time misses the freeze window), no
 * parallel per-table workers, no source fingerprint (F0/F1) check, no tailnet/TLS refusal on the source host, no
 * schema-diff-based verify item 4, no smoke/API-sample verify items 6-7. Every phase is otherwise real: it runs
 * against a live MariaDB source and a live PostgreSQL target, not a dry simulation (unless `--dry-run`).
 */
final class CopyToPostgresService
{
    /** @var DatabaseModel[] */
    protected array $databaseModels;

    protected PostgresDdlRenderer $renderer;

    /**
     * @param DatabaseModel[] $databaseModels Already filtered by --table; one DatabaseModel per entity to copy.
     */
    public function __construct(
        protected readonly Connection $source,
        protected readonly Connection $target,
        array $databaseModels,
        protected readonly SymfonyStyle $io,
        protected readonly int $chunkSize = 5000,
        protected readonly bool $dryRun = false,
    ) {
        $this->databaseModels = $databaseModels;
        $this->renderer = new PostgresDdlRenderer();
    }

    /**
     * Source tables with no matching entity among {@see $databaseModels} — reported, never copied (Mig §5.1:
     * "skip tables that exist in source but have no entity"). $allEntityTableNames must be the FULL entity table
     * set (not just the --table-filtered one) so a --table run does not falsely flag every other table.
     *
     * @param string[] $allEntityTableNames
     * @return string[]
     */
    public function sourceTablesWithoutEntity(string $sourceDatabaseName, array $allEntityTableNames): array
    {
        $sourceTables = $this->source->fetchFirstColumn(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\'',
            [$sourceDatabaseName]
        );
        $knownTables = array_flip($allEntityTableNames);
        return array_values(array_filter($sourceTables, static fn (string $table): bool => !isset($knownTables[$table])));
    }

    /**
     * Phase `schema` (Mig §5.1 step 2 opening clause): `ensure()` once, then every table's CREATE TABLE statement
     * — primary key only, no secondary/unique index, FK, CHECK or trigger. `PostgresDdlRenderer::renderCreate()`
     * already returns those on their own `DdlStatement::PHASE_CREATE_TABLE` (the table body only ever carries
     * columns + inline `PRIMARY KEY`; indexes/FKs are separate, later-phase statements the renderer returns
     * alongside it) — no renderer change was needed to get a clean PK-only CREATE TABLE out of it.
     *
     * @return array<string, array{seconds: float}>
     */
    public function runSchema(): array
    {
        $results = [];
        if ($this->dryRun) {
            foreach ($this->renderer->ensure() as $statement) {
                $this->io->text('[dry-run] ' . $statement->sql);
            }
        } else {
            foreach ($this->renderer->ensure() as $statement) {
                $this->target->executeStatement($statement->sql);
            }
        }

        foreach ($this->databaseModels as $databaseModel) {
            $start = microtime(true);
            $tableStatements = array_values(array_filter(
                $this->renderer->renderCreate($databaseModel),
                static fn (DdlStatement $statement): bool => $statement->phase === DdlStatement::PHASE_CREATE_TABLE
            ));
            foreach (DdlStatement::sortByPhase($tableStatements) as $statement) {
                if ($this->dryRun) {
                    $this->io->text('[dry-run] ' . $statement->sql);
                } else {
                    $this->target->executeStatement($statement->sql);
                }
            }
            $results[$databaseModel->sqlTableName] = ['seconds' => microtime(true) - $start];
        }
        return $results;
    }

    /**
     * Phase `data` (Mig §5.1 step 2): id-ordered chunks per table, streamed through
     * `PDO::pgsqlCopyFromArray()` (COPY text). Restartable per table: a restart truncates the target table first
     * (Mig §5.1 "Parallel and restartable per table (a restart truncates)").
     *
     * @return array<string, array{rows: int, seconds: float}>
     */
    public function runData(): array
    {
        $results = [];
        foreach ($this->databaseModels as $databaseModel) {
            if (!$this->sourceHasTable($databaseModel->sqlTableName)) {
                // An entity table the source database never had (e.g. a DDD core entity the app does not use):
                // created empty by the schema phase, nothing to copy or verify.
                $results[$databaseModel->sqlTableName] = ['rows' => 0, 'seconds' => 0.0, 'absentInSource' => true];
                continue;
            }
            $start = microtime(true);
            $rows = $this->copyTable($databaseModel);
            $results[$databaseModel->sqlTableName] = ['rows' => $rows, 'seconds' => microtime(true) - $start];
        }
        return $results;
    }

    /** @var array<string, bool>|null Source table names (MariaDB, current database). */
    protected ?array $sourceTables = null;

    protected function sourceHasTable(string $table): bool
    {
        $this->sourceTables ??= array_fill_keys(
            $this->source->fetchFirstColumn('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()'),
            true
        );
        return isset($this->sourceTables[$table]);
    }

    /** @var array<string, array<string, bool>> Source column names per table. */
    protected array $sourceColumns = [];

    /**
     * The entity's persisted columns that also exist in the source table. A column the entity declares but the
     * source never had (schema drift, e.g. Radbonus CacheScopeInvalidations.locationId) is reported once and left to
     * the target's DEFAULT / NULL — the copy moves what exists, it does not invent data.
     *
     * @return DatabaseColumn[]
     */
    /** Target bytes per chunk: rows are fetched buffered, so a chunk must fit comfortably in PHP's memory. */
    public const int CHUNK_TARGET_BYTES = 32 * 1024 * 1024;

    /** @var array<string, int> */
    protected array $chunkSizes = [];

    /**
     * Rows per chunk for a table: the configured chunk size, capped so a chunk stays near CHUNK_TARGET_BYTES given the
     * source's average row length (Radbonus Tracks carry multi-MB trackDetails blobs; Dineful MediaItems ~1 MB images).
     */
    protected function chunkSizeFor(string $table): int
    {
        if (!isset($this->chunkSizes[$table])) {
            $averageRowLength = (int)($this->source->fetchOne(
                'SELECT AVG_ROW_LENGTH FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            ) ?: 0);
            // InnoDB's AVG_ROW_LENGTH excludes most off-page blob bytes; also measure twice the average over a sample of real rows.
            $columns = $this->source->fetchFirstColumn(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
                . " AND DATA_TYPE IN ('blob','mediumblob','longblob','text','mediumtext','longtext','json')",
                [$table]
            );
            if ($columns !== []) {
                $lengthSum = implode(' + ', array_map(static fn (string $c): string => "COALESCE(LENGTH(`$c`), 0)", $columns));
                $sampledLength = (int)($this->source->fetchOne(
                    "SELECT 2 * AVG(l) FROM (SELECT $lengthSum AS l FROM `$table` ORDER BY `id` DESC LIMIT 2000) AS sample"
                ) ?: 0);
                $averageRowLength = max($averageRowLength, $sampledLength);
            }
            $this->chunkSizes[$table] = max(1, min($this->chunkSize, intdiv(self::CHUNK_TARGET_BYTES, max(1, $averageRowLength))));
        }
        return $this->chunkSizes[$table];
    }

    protected function columnsPresentInSource(DatabaseModel $databaseModel): array
    {
        $table = $databaseModel->sqlTableName;
        if (!isset($this->sourceColumns[$table])) {
            $this->sourceColumns[$table] = array_fill_keys($this->source->fetchFirstColumn(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            ), true);
            $missing = [];
            foreach ($databaseModel->columns->getElements() as $column) {
                if (!$column->ignoreProperty && !isset($this->sourceColumns[$table][$column->name])) {
                    $missing[] = $column->name;
                }
            }
            if ($missing !== []) {
                $this->io->note("\"$table\": column(s) absent in the source, left to the target default: " . implode(', ', $missing));
            }
        }
        return array_values(array_filter(
            $databaseModel->columns->getElements(),
            fn (DatabaseColumn $column): bool => !$column->ignoreProperty && isset($this->sourceColumns[$table][$column->name])
        ));
    }

    protected function copyTable(DatabaseModel $databaseModel): int
    {
        $table = $databaseModel->sqlTableName;
        /** @var DatabaseColumn[] $columns */
        $columns = $this->columnsPresentInSource($databaseModel);
        $fieldNames = array_map(static fn (DatabaseColumn $column): string => $column->name, $columns);

        if ($this->dryRun) {
            $count = (int)$this->source->fetchOne("SELECT COUNT(*) FROM `$table`");
            $this->io->text("[dry-run] would copy $count row(s) of \"$table\" (" . implode(', ', $fieldNames) . ')');
            return $count;
        }

        $this->target->executeStatement('TRUNCATE TABLE "' . $table . '"');

        $selectExpressions = implode(
            ', ',
            array_map(static fn (DatabaseColumn $column): string => CopyValueCodec::mariaDbSelectExpression($column), $columns)
        );
        $quotedFields = implode(',', array_map(static fn (string $name): string => '"' . $name . '"', $fieldNames));

        /** @var PDO $targetPdo */
        $targetPdo = $this->target->getNativeConnection();

        $copiedRows = 0;
        $lastId = 0;
        while (true) {
            $chunk = $this->source->fetchAllAssociative(
                "SELECT $selectExpressions FROM `$table` WHERE `id` > ? ORDER BY `id` ASC LIMIT ?",
                [$lastId, $this->chunkSizeFor($table)],
                [PDO::PARAM_INT, PDO::PARAM_INT]
            );
            if ($chunk === []) {
                break;
            }

            $copyRows = [];
            foreach ($chunk as $row) {
                $id = (int)$row['id'];
                $fields = [];
                foreach ($columns as $column) {
                    $logicalValue = CopyValueCodec::canonicalizeSourceValueForCopy($column, $row[$column->name], $table, $id);
                    $fields[] = CopyValueCodec::escapeField($logicalValue);
                }
                $copyRows[] = implode("\t", $fields);
                $lastId = $id;
            }

            // pgsqlCopyFromArray() does not quote $tableName itself (bare mixed-case names fold to lowercase and
            // "miss" a quoted-mixed-case table) — quote it explicitly, same as every other identifier here.
            $ok = $targetPdo->pgsqlCopyFromArray('"' . $table . '"', $copyRows, "\t", CopyValueCodec::PGSQL_COPY_FROM_ARRAY_NULL_AS, $quotedFields);
            if (!$ok) {
                throw new InternalErrorException("app:db:copy-to-postgres: COPY into \"$table\" failed.");
            }
            $copiedRows += count($chunk);
        }

        return $copiedRows;
    }

    /**
     * Phase `finalise` (Mig §5.1 step 3, minus triggers — PostgresDdlRenderer has no PL/pgSQL trigger body yet,
     * step 7): secondary/unique indexes and FKs (`NOT VALID` + `VALIDATE` is step 7 too — the renderer's FK
     * statement is a plain `ADD CONSTRAINT`, which still validates inline; fine at spike scale, not at production
     * scale, hence step 7), then the identity reset per table.
     *
     * @return array<string, array{seconds: float}>
     */
    public function runFinalise(): array
    {
        $results = [];
        foreach ($this->databaseModels as $databaseModel) {
            $start = microtime(true);
            $statements = array_values(array_filter(
                $this->renderer->renderCreate($databaseModel),
                static fn (DdlStatement $statement): bool => $statement->phase !== DdlStatement::PHASE_CREATE_TABLE
            ));
            foreach (DdlStatement::sortByPhase($statements) as $statement) {
                if ($this->dryRun) {
                    $this->io->text('[dry-run] ' . $statement->sql);
                } else {
                    $this->target->executeStatement($statement->sql);
                }
            }
            if (!$this->dryRun) {
                $this->resetIdentity($databaseModel);
            }
            $results[$databaseModel->sqlTableName] = ['seconds' => microtime(true) - $start];
        }
        return $results;
    }

    protected function resetIdentity(DatabaseModel $databaseModel): void
    {
        $table = $databaseModel->sqlTableName;
        $primaryKeyColumn = $databaseModel->columns->getPrimaryKeyColumn();
        if ($primaryKeyColumn === null || !$primaryKeyColumn->hasAutoIncrement) {
            return;
        }

        $sourceAutoIncrement = (int)($this->source->fetchOne(
            'SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) ?: 0);
        $maxTargetId = (int)$this->target->fetchOne('SELECT COALESCE(MAX("id"), 0) FROM "' . $table . '"');
        $nextValue = max($sourceAutoIncrement, $maxTargetId + 1);

        // pg_get_serial_sequence() parses its first argument with the same rules as an unquoted SQL identifier
        // (regclass-style) — a mixed-case table name must arrive already double-quoted, or it folds to lowercase
        // and is never found.
        $this->target->executeStatement(
            "SELECT setval(pg_get_serial_sequence(?, 'id'), ?, false)",
            ['"' . $table . '"', $nextValue]
        );
    }

    /**
     * Phase `verify` (Mig §5.5 items 1-3 only — see class docblock for 4-7): per table, equal id sets, equal
     * canonicalised row hash, and the identity next value at least covering both sides' max id.
     *
     * @return array<string, array{idSetMatches: bool, rowMismatches: string[], identityOk: bool, rows: int}>
     */
    public function runVerify(): array
    {
        $results = [];
        foreach ($this->databaseModels as $databaseModel) {
            if (!$this->sourceHasTable($databaseModel->sqlTableName)) {
                continue; // absent in the source: nothing was copied, nothing to compare
            }
            $results[$databaseModel->sqlTableName] = $this->verifyTable($databaseModel);
        }
        return $results;
    }

    /** @return array{idSetMatches: bool, rowMismatches: string[], identityOk: bool, rows: int} */
    protected function verifyTable(DatabaseModel $databaseModel): array
    {
        $table = $databaseModel->sqlTableName;
        /** @var DatabaseColumn[] $columns */
        $columns = $this->columnsPresentInSource($databaseModel);

        $sourceIds = array_map('intval', $this->source->fetchFirstColumn("SELECT `id` FROM `$table` ORDER BY `id`"));
        $targetIds = array_map('intval', $this->target->fetchFirstColumn('SELECT "id" FROM "' . $table . '" ORDER BY "id"'));
        $idSetMatches = $sourceIds === $targetIds;

        $sourceSelect = implode(
            ', ',
            array_map(static fn (DatabaseColumn $c): string => CopyValueCodec::mariaDbVerifySelectExpression($c), $columns)
        );
        $targetSelect = implode(
            ', ',
            array_map(static fn (DatabaseColumn $c): string => CopyValueCodec::postgresSelectExpression($c), $columns)
        );

        // Compared in id windows of the chunk size: a table can hold more data than PHP may load at once
        // (Dineful MediaItems: ~1.1 GB in 764 rows), so neither side is ever fetched whole.
        $mismatches = [];
        foreach (array_chunk($sourceIds, $this->chunkSizeFor($table)) as $idWindow) {
            $firstId = $idWindow[0];
            $lastId = $idWindow[count($idWindow) - 1];
            $sourceRows = [];
            foreach ($this->source->fetchAllAssociative(
                "SELECT $sourceSelect FROM `$table` WHERE `id` BETWEEN ? AND ? ORDER BY `id`",
                [$firstId, $lastId]
            ) as $row) {
                $sourceRows[(int)$row['id']] = $row;
            }
            $targetRows = [];
            foreach ($this->target->fetchAllAssociative(
                'SELECT ' . $targetSelect . ' FROM "' . $table . '" WHERE "id" BETWEEN ? AND ? ORDER BY "id"',
                [$firstId, $lastId]
            ) as $row) {
                $targetRows[(int)$row['id']] = $row;
            }
            foreach ($idWindow as $id) {
                if (!isset($targetRows[$id], $sourceRows[$id])) {
                    continue; // already surfaced by the id-set mismatch
                }
                $sourceCanonical = $this->canonicalRow($columns, $sourceRows[$id], fromPostgres: false);
                $targetCanonical = $this->canonicalRow($columns, $targetRows[$id], fromPostgres: true);
                if ($sourceCanonical !== $targetCanonical) {
                    $diffColumns = [];
                    foreach ($sourceCanonical as $columnName => $value) {
                        if (($targetCanonical[$columnName] ?? null) !== $value) {
                            $diffColumns[] = $columnName;
                        }
                    }
                    $mismatches[] = "id=$id: " . implode(',', $diffColumns);
                }
            }
        }

        $primaryKeyColumn = $databaseModel->columns->getPrimaryKeyColumn();
        $identityOk = true;
        if ($primaryKeyColumn !== null && $primaryKeyColumn->hasAutoIncrement) {
            $sourceAutoIncrement = (int)($this->source->fetchOne(
                'SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            ) ?: 0);
            $maxSourceId = $sourceIds === [] ? 0 : max($sourceIds);
            $expectedMinimum = max($sourceAutoIncrement, $maxSourceId + 1);
            $targetNextValue = (int)$this->target->fetchOne(
                "SELECT last_value + CASE WHEN is_called THEN 1 ELSE 0 END FROM " . $this->targetSequenceName($table)
            );
            $identityOk = $targetNextValue >= $expectedMinimum;
        }

        return [
            'idSetMatches' => $idSetMatches,
            'rowMismatches' => $mismatches,
            'identityOk' => $identityOk,
            'rows' => count($sourceIds),
        ];
    }

    /** @param DatabaseColumn[] $columns @return array<string, string|null> */
    protected function canonicalRow(array $columns, array $row, bool $fromPostgres): array
    {
        $canonical = [];
        foreach ($columns as $column) {
            $canonical[$column->name] = CopyValueCodec::canonicalizeValueForVerify($column, $row[$column->name], $fromPostgres);
        }
        return $canonical;
    }

    protected function targetSequenceName(string $table): string
    {
        $sequence = $this->target->fetchOne("SELECT pg_get_serial_sequence(?, 'id')", ['"' . $table . '"']);
        if (!is_string($sequence) || $sequence === '') {
            throw new InternalErrorException("app:db:copy-to-postgres: no identity sequence found for \"$table\".\"id\".");
        }
        return $sequence;
    }
}
