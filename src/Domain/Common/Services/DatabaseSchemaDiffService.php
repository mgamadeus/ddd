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
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModels;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBColumnDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBColumnDiffs;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBForeignKeyDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBForeignKeyDiffs;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBIndexDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBIndexDiffs;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiffs;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTriggerDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTriggerDiffs;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBVirtualColumnDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBVirtualColumnDiffs;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use Doctrine\DBAL\Exception;
use ReflectionException;

/**
 * Computes a structured diff between the code-derived schema (built from DDD entities) and the
 * live database, and applies that diff back when requested.
 *
 * Pipeline:
 *   1. Target side: {@see EntityModelGeneratorService::getDatabaseModels()} (already filters out
 *      overridden DDD entities — see EntityModelGeneratorService::filterOutOverriddenEntities()).
 *   2. Current side: {@see DatabaseSchemaIntrospectionService} reads INFORMATION_SCHEMA.
 *   3. Per table: canonicalise both sides into the same struct shape, run three-loop set diff per
 *      aspect (ADD/DROP/MODIFY), classify severity, render SQL.
 *
 * The result {@see DBTableDiffs} contains only tables that actually differ — NO_CHANGE entries
 * are dropped before returning.
 */
class DatabaseSchemaDiffService
{
    /** @var string[] Live tables to ignore (Doctrine/Symfony bookkeeping that the entity generator does not own). */
    public const array DEFAULT_IGNORED_LIVE_TABLES = [
        'doctrine_migration_versions',
        'messenger_messages',
    ];

    protected DatabaseSchemaIntrospectionService $introspectionService;

    /**
     * @param DatabaseSchemaIntrospectionService|null $introspectionService Optional override —
     *        the diff service holds the introspection cache, so tests / consumers that want a
     *        pre-warmed or pre-mocked introspector inject it here. Default constructs a fresh one.
     */
    public function __construct(?DatabaseSchemaIntrospectionService $introspectionService = null)
    {
        $this->introspectionService = $introspectionService ?? new DatabaseSchemaIntrospectionService();
    }

    /**
     * Computes the structural diff for all entity-backed tables plus any live tables that exist
     * in the DB but are no longer in code.
     *
     * @param string[]|null $entityClasses Optional filter: restrict the diff to specific entity classes.
     *                                     When null, all entities are considered.
     * @throws Exception
     * @throws ReflectionException
     */
    public function computeDiffs(?array $entityClasses = null): DBTableDiffs
    {
        $databaseModels = EntityModelGeneratorService::getDatabaseModels($entityClasses);
        $liveTableNames = $this->introspectionService->getLiveTableNames();
        $ignoredLiveTables = $this->getIgnoredLiveTables();

        $diffs = new DBTableDiffs();
        $targetTableNames = [];

        foreach ($databaseModels->getElements() as $databaseModel) {
            // STI subclasses are owned by the parent's table — they emit no SQL and would create
            // a spurious DROP_TABLE entry for a table that does not exist live.
            if ($databaseModel->parentEntityCLassWithNamespace !== null) {
                continue;
            }
            $tableName = $databaseModel->sqlTableName;
            $targetTableNames[$tableName] = true;

            $current = in_array($tableName, $liveTableNames, true)
                ? $this->introspectionService->introspectTable($tableName)
                : null;

            $tableDiff = $this->computeTableDiff($databaseModel, $current);
            if ($tableDiff->changeType === DBTableDiff::CHANGE_TYPE_NO_CHANGE) {
                continue;
            }
            $diffs->add($tableDiff);
        }

        foreach ($liveTableNames as $liveTableName) {
            if (isset($targetTableNames[$liveTableName])) {
                continue;
            }
            if (in_array($liveTableName, $ignoredLiveTables, true)) {
                continue;
            }
            $dropDiff = $this->buildDropTableDiff($liveTableName);
            $diffs->add($dropDiff);
        }

        // Decorate each diff with the Production-Guard signal: live table stats + whether direct
        // apply through the admin UI is blocked. The frontend uses these fields to render a
        // warning banner and disable the Apply button — see DBTableDiff::$directApplyBlocked.
        foreach ($diffs->getElements() as $diff) {
            $this->populateProductionGuardSignal($diff);
        }

        return $diffs;
    }

    /**
     * Computes and writes the Production-Guard signal onto a single {@see DBTableDiff}:
     *   • tableSizeMb + tableRowCount: live stats (null for CREATE_TABLE)
     *   • directApplyBlocked: true when the table is large AND the diff contains COPY-forcing ops
     *   • directApplyBlockReason: pre-formatted human-readable explanation
     *   • copyForcingOperations: structured list of risky operations
     *
     * Pure decorator — never throws. The same logic short-circuits {@see self::assertSafeForDirectApply()}
     * at apply time; this method is the read-side counterpart that lets the UI surface the block
     * before the user even attempts to apply.
     */
    protected function populateProductionGuardSignal(DBTableDiff $diff): void
    {
        if ($diff->changeType === DBTableDiff::CHANGE_TYPE_CREATE_TABLE) {
            return; // No live table yet → no size/rows, no guard applies.
        }
        $stats = $this->getTableSizeStats($diff->sqlTableName);
        $diff->tableSizeMb = $stats['size_mb'] ?? null;
        $diff->tableRowCount = $stats['row_count'] ?? null;

        $isLarge = ($diff->tableSizeMb !== null && $diff->tableSizeMb > self::LARGE_TABLE_SIZE_THRESHOLD_MB)
            || ($diff->tableRowCount !== null && $diff->tableRowCount > self::LARGE_TABLE_ROW_THRESHOLD);
        if (!$isLarge) {
            return;
        }
        $risky = $this->detectCopyForcingOperations($diff);
        if (empty($risky)) {
            return;
        }
        $diff->directApplyBlocked = true;
        $diff->copyForcingOperations = $risky;
        $diff->directApplyBlockReason = $this->buildProductionGuardMessage(
            $diff->sqlTableName,
            $diff->tableSizeMb,
            $diff->tableRowCount,
            $risky
        );
    }

    /**
     * Builds the human-readable refusal message for the Production-Guard. Shared between the
     * compute-time decorator ({@see self::populateProductionGuardSignal()}) and the apply-time
     * thrower ({@see self::assertSafeForDirectApply()}) so both paths surface the same text.
     *
     * @param string[] $riskyOperations
     */
    protected function buildProductionGuardMessage(
        string $tableName,
        ?int $sizeMb,
        ?int $rowCount,
        array $riskyOperations
    ): string {
        $sizeThreshold = self::LARGE_TABLE_SIZE_THRESHOLD_MB;
        $rowThreshold = self::LARGE_TABLE_ROW_THRESHOLD;
        $sizeShown = $sizeMb !== null ? "{$sizeMb} MB" : 'unknown MB';
        $rowsShown = $rowCount !== null ? number_format($rowCount) . ' rows' : 'unknown rows';
        $offending = "    • " . implode("\n    • ", $riskyOperations);
        return "Direct apply on large table `$tableName` ($sizeShown, $rowsShown — exceeds thresholds {$sizeThreshold} MB / " . number_format($rowThreshold) . " rows) is BLOCKED.\n\n" .
            "The diff contains operations that force ALGORITHM=COPY under Galera TOI, which would block the entire cluster for the duration of the table rewrite:\n" .
            $offending . "\n\n" .
            "Use pt-online-schema-change instead.\n\n" .
            "Recommended: invoke the `rb-db-online-schema-update-specialist` skill via a Claude Code Agent. It bundles the pre-flight checks, triple-reasoning gate, dry-run, tmux run procedure and post-verification specific to this cluster.\n\n" .
            "Programmatic-only override: call `applyDiff(\$diff, …, bypassProductionGuard: true)` from a CLI command. The admin HTTP API does NOT expose this flag by design.";
    }

    /**
     * Applies every diff in the set in insertion order, wrapped in a single connection-level
     * disabling of foreign key checks. Re-introspects the affected tables on success and returns
     * a refreshed diff set (typically empty when everything applied cleanly).
     *
     * @throws Exception
     * @throws ReflectionException
     */
    public function applyDiffs(
        DBTableDiffs $diffs,
        bool $disableForeignKeyChecks = true,
        bool $bypassProductionGuard = false
    ): DBTableDiffs {
        if (!$bypassProductionGuard) {
            foreach ($diffs->getElements() as $diff) {
                $this->assertSafeForDirectApply($diff);
            }
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        if ($disableForeignKeyChecks) {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0;');
        }
        try {
            foreach ($diffs->getElements() as $diff) {
                $this->executeTableDiff($diff);
            }
        } finally {
            if ($disableForeignKeyChecks) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1;');
            }
        }
        $this->introspectionService->invalidateCache();
        return $this->computeDiffs();
    }

    /**
     * Applies a single table's diff. Same FK-check handling as applyDiffs.
     *
     * @throws Exception
     * @throws ReflectionException
     */
    public function applyDiff(
        DBTableDiff $diff,
        bool $disableForeignKeyChecks = true,
        bool $bypassProductionGuard = false
    ): DBTableDiffs {
        $set = new DBTableDiffs();
        $set->add($diff);
        return $this->applyDiffs($set, $disableForeignKeyChecks, $bypassProductionGuard);
    }

    /**
     * Hard thresholds above which direct ALTER on Galera-replicated tables risks cluster-wide
     * stalls. A table is "large" if EITHER it exceeds {@see self::LARGE_TABLE_SIZE_THRESHOLD_MB} MB
     * total size OR it exceeds {@see self::LARGE_TABLE_ROW_THRESHOLD} rows. Both axes matter:
     *
     *   • Size triggers TOI block duration (COPY rewrites every page).
     *   • Row count triggers triggers triggers — even narrow rows slow pt-osc-style chunking and
     *     amplify Galera flow-control pressure.
     *
     * Below these thresholds the table can absorb a brief TOI block from direct ALTER; above, the
     * operation must run through pt-online-schema-change instead.
     */
    public const int LARGE_TABLE_SIZE_THRESHOLD_MB = 100;

    public const int LARGE_TABLE_ROW_THRESHOLD = 100_000;

    /**
     * Guard against accidentally applying COPY-forcing or otherwise expensive schema changes on
     * large production tables through the admin UI. The check is purely a refusal: it throws and
     * does not silently downgrade behaviour. Operators who genuinely need to apply such a diff must
     * either:
     *
     *   1. Use pt-online-schema-change via the `rb-db-online-schema-update-specialist` skill
     *      (Claude Code Agent: invoke that skill and follow the triple-reasoning + tmux run).
     *   2. Pass `$bypassProductionGuard = true` explicitly when calling the service from a CLI
     *      command or other trusted programmatic path — never wired through the admin HTTP API.
     *
     * The check covers operations the skill's cheatsheet flags as COPY-forcing on big tables:
     *   • Column MODIFY with sqlType / length / vectorDimensions change
     *   • Column MODIFY with requiresFullReset (VECTOR re-dimensioning)
     *   • Virtual column MODIFY (always DROP+ADD, MySQL forbids ALTER on generation expressions)
     *   • Index ADD where type is FULLTEXT
     *
     * A table is considered "large" when its byte size OR its row count exceeds the configured
     * thresholds — see {@see self::isLargeTable()}. Below both thresholds the guard skips entirely
     * because direct ALTER on such tables is fast enough that the brief TOI block is invisible.
     *
     * @throws BadRequestException
     */
    public function assertSafeForDirectApply(DBTableDiff $diff): void
    {
        // If computeDiffs() already decorated the diff with the guard signal, reuse it. Otherwise
        // compute on-the-fly — callers like CLI commands sometimes build diffs by hand and skip
        // computeDiffs().
        if ($diff->directApplyBlocked && $diff->directApplyBlockReason !== null) {
            throw new BadRequestException($diff->directApplyBlockReason);
        }
        $sizeMb = null;
        $rowCount = null;
        if (!$this->isLargeTable($diff->sqlTableName, $sizeMb, $rowCount)) {
            return;
        }
        $riskyOperations = $this->detectCopyForcingOperations($diff);
        if (empty($riskyOperations)) {
            return;
        }
        throw new BadRequestException(
            $this->buildProductionGuardMessage($diff->sqlTableName, $sizeMb, $rowCount, $riskyOperations)
        );
    }

    /**
     * True when the table exceeds either the size or the row-count threshold. Both metrics are
     * looked up from `INFORMATION_SCHEMA.TABLES` in a single query. Out-parameters surface the
     * exact figures so the caller can include them in error messages.
     *
     * `table_rows` from INFORMATION_SCHEMA is an *estimate* on InnoDB (statistics-derived, not a
     * COUNT(*)). For guardrail purposes the estimate is more than accurate enough — we're checking
     * orders of magnitude, not exact thresholds.
     */
    public function isLargeTable(string $sqlTableName, ?int &$sizeMb = null, ?int &$rowCount = null): bool
    {
        $stats = $this->getTableSizeStats($sqlTableName);
        $sizeMb = $stats['size_mb'] ?? null;
        $rowCount = $stats['row_count'] ?? null;
        if ($sizeMb === null && $rowCount === null) {
            return false;
        }
        return ($sizeMb !== null && $sizeMb > self::LARGE_TABLE_SIZE_THRESHOLD_MB)
            || ($rowCount !== null && $rowCount > self::LARGE_TABLE_ROW_THRESHOLD);
    }

    /**
     * Returns the live table's size in MB and estimated row count, or null when the table is
     * missing or the query fails.
     *
     * @return array{size_mb: ?int, row_count: ?int}|null
     */
    public function getTableSizeStats(string $sqlTableName): ?array
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        try {
            $row = $connection->fetchAssociative(
                'SELECT ROUND((data_length + index_length) / 1024 / 1024) AS size_mb,
                        table_rows AS row_count
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                ['table' => $sqlTableName]
            );
        } catch (\Throwable) {
            return null;
        }
        if (!$row) {
            return null;
        }
        return [
            'size_mb' => $row['size_mb'] !== null ? (int)$row['size_mb'] : null,
            'row_count' => $row['row_count'] !== null ? (int)$row['row_count'] : null,
        ];
    }

    /**
     * Enumerates which child diffs in a table-level diff are COPY-forcing under MariaDB/InnoDB.
     * Used by {@see self::assertSafeForDirectApply()} to build the error message. Returns an empty
     * array when the diff is online-safe (i.e. all operations are INSTANT- or INPLACE-eligible).
     *
     * @return string[] Human-readable descriptions of the risky operations.
     */
    protected function detectCopyForcingOperations(DBTableDiff $diff): array
    {
        $risky = [];
        // Column attribute changes that force or risk ALGORITHM=COPY:
        //   - sqlType: type change (INT→BIGINT, ENUM→VARCHAR, etc.) → always COPY
        //   - length: VARCHAR shrink/grow → typically COPY
        //   - vectorDimensions: VECTOR re-dim → DROP+ADD+backfill
        //   - allowsNull: nullability change on a large table can force a full row-scan ALTER under
        //     Galera TOI. Relax (NOT NULL→NULL) is usually INPLACE-fast, tighten (NULL→NOT NULL)
        //     needs to validate every row and frequently falls back to COPY. Treat both as risky
        //     on large tables — the operator should explicitly choose pt-osc with the right hints.
        $copyForcingColumnAttributes = ['sqlType', 'length', 'vectorDimensions', 'allowsNull'];

        foreach ($diff->columnDiffs->getElements() as $cd) {
            if ($cd->changeKind !== DBColumnDiff::CHANGE_KIND_MODIFY) {
                continue;
            }
            if ($cd->requiresFullReset) {
                $risky[] = "MODIFY column `$cd->columnName` requires full reset (VECTOR re-dimensioning forces DROP+ADD+backfill)";
                continue;
            }
            $copyForcingChanges = array_intersect($cd->changedAttributes, $copyForcingColumnAttributes);
            if (!empty($copyForcingChanges)) {
                $reason = in_array('allowsNull', $copyForcingChanges, true) && count($copyForcingChanges) === 1
                    ? 'nullability change can force full row-scan ALTER on large tables (use pt-osc with INPLACE/LOCK hint)'
                    : 'column-type or size change forces ALGORITHM=COPY';
                $risky[] = "MODIFY column `$cd->columnName` — changes " . implode(', ', $copyForcingChanges) . " ($reason)";
            }
        }
        foreach ($diff->virtualColumnDiffs->getElements() as $vcd) {
            if ($vcd->changeKind === DBVirtualColumnDiff::CHANGE_KIND_MODIFY) {
                $risky[] = "MODIFY virtual column `$vcd->columnName` (generation-expression change requires DROP+ADD — MySQL forbids in-place ALTER)";
            }
        }
        foreach ($diff->indexDiffs->getElements() as $id) {
            if ($id->changeKind === DBIndexDiff::CHANGE_KIND_ADD
                && $id->targetIndex !== null
                && $id->targetIndex->indexType === DatabaseIndex::TYPE_FULLTEXT) {
                $indexName = $id->targetIndex->indexName ?? '(unnamed)';
                $risky[] = "ADD FULLTEXT INDEX `$indexName` (FULLTEXT index creation forces ALGORITHM=COPY)";
            }
        }
        return $risky;
    }

    /**
     * @return string[]
     */
    protected function getIgnoredLiveTables(): array
    {
        $envValue = Config::getEnv('DB_DIFF_IGNORED_TABLES');
        if (!$envValue) {
            return self::DEFAULT_IGNORED_LIVE_TABLES;
        }
        $custom = array_filter(array_map('trim', explode(',', (string)$envValue)));
        return array_values(array_unique(array_merge(self::DEFAULT_IGNORED_LIVE_TABLES, $custom)));
    }

    /**
     * @throws Exception
     */
    protected function executeTableDiff(DBTableDiff $diff): void
    {
        if ($diff->sql === '') {
            return;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        foreach ($diff->sqlStatements as $statement) {
            // Defensive: each sqlStatements entry SHOULD already be a single executable statement.
            // CREATE_TABLE diffs originally come from DatabaseModel::getSql() as one multi-statement
            // blob and we pre-split them in computeTableDiff. We re-split here as belt-and-braces
            // so a future caller cannot accidentally hand us a multi-statement entry that Doctrine
            // DBAL would reject (executeStatement() goes through prepare+execute → MySQL forbids
            // multi-statement prepared queries by default).
            foreach ($this->splitMultiStatementSql($statement) as $singleStatement) {
                $connection->executeStatement($singleStatement);
            }
        }
    }

    /**
     * Splits a multi-statement SQL string into individually-executable statements. Handles the
     * comment banners DatabaseModel::getSql() emits (lines starting with "#"). DDL statements do
     * not contain ";" in string literals in any current DDD-generated SQL, so naive splitting on
     * ";" is safe — revisit if/when triggers with BEGIN…END blocks land in this generator path.
     *
     * @return string[]
     */
    protected function splitMultiStatementSql(string $sql): array
    {
        $withoutComments = (string)preg_replace('/^\s*#.*$/m', '', $sql);
        $statements = [];
        foreach (explode(';', $withoutComments) as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
        }
        return $statements;
    }

    /**
     * @throws ReflectionException
     */
    protected function computeTableDiff(DatabaseModel $databaseModel, ?DBCanonicalTable $current): DBTableDiff
    {
        $diff = new DBTableDiff();
        $diff->sqlTableName = $databaseModel->sqlTableName;
        $diff->entityClassWithNamespace = $databaseModel->entityClassWithNamespace;

        if ($current === null) {
            $diff->changeType = DBTableDiff::CHANGE_TYPE_CREATE_TABLE;
            $createTableSql = $databaseModel->getSql();
            $diff->sql = $createTableSql;
            // Pre-split so executeStatement receives single statements (see splitMultiStatementSql).
            $diff->sqlStatements = $this->splitMultiStatementSql($createTableSql);
            $diff->severity = DBTableDiff::SEVERITY_ADDITIVE;
            return $diff;
        }

        $targetColumns = $this->canonicaliseTargetColumns($databaseModel);
        $targetVirtualColumns = $this->canonicaliseTargetVirtualColumns($databaseModel);
        $targetIndexes = $this->canonicaliseTargetIndexes($databaseModel);
        $targetForeignKeys = $this->canonicaliseTargetForeignKeys($databaseModel);

        $diff->columnDiffs = $this->diffColumns($databaseModel, $targetColumns, $current->columns);
        $diff->virtualColumnDiffs = $this->diffVirtualColumns(
            $databaseModel,
            $targetVirtualColumns,
            $current->virtualColumns
        );
        $diff->indexDiffs = $this->diffIndexes($databaseModel, $targetIndexes, $current->indexes);
        $diff->foreignKeyDiffs = $this->diffForeignKeys(
            $databaseModel,
            $targetForeignKeys,
            $current->foreignKeys
        );
        $diff->triggerDiffs = $this->diffTriggers($databaseModel, $current->triggers);

        if (strcasecmp($databaseModel->collation, $current->collation) !== 0) {
            $diff->collationChange = [
                'from' => $current->collation,
                'to'   => $databaseModel->collation,
            ];
        }

        if ($diff->isEmpty()) {
            $diff->changeType = DBTableDiff::CHANGE_TYPE_NO_CHANGE;
            return $diff;
        }

        $diff->changeType = DBTableDiff::CHANGE_TYPE_ALTER_TABLE;
        $diff->sqlStatements = $this->assemblePhaseOrderedStatements($databaseModel, $diff);
        $diff->sql = $diff->sqlStatements ? implode(";\n", $diff->sqlStatements) . ';' : '';
        $diff->severity = $this->classifySeverity($diff);

        return $diff;
    }

    /**
     * Assembles SQL statements in dependency-safe order so the apply path cannot run a DROP COLUMN
     * while an index still references it, an ADD FK before the referenced column exists, etc.
     *
     * Phases (executed in order):
     *   0. DROP triggers (covers DROP + the drop-half of MODIFY) — first so subsequent DDL is not
     *      perturbed by stale BEFORE triggers that reference about-to-be-dropped columns.
     *   1. DROP foreign keys (covers DROP + the drop-half of MODIFY)
     *   2. DROP indexes
     *   3. DROP virtual columns (covers DROP + the drop-half of MODIFY)
     *   4. DROP real columns (+ vector-reset MODIFY drop-half)
     *   5. Table collation change
     *   6. MODIFY real columns (single in-place statement; excludes vector-reset MODIFYs)
     *   7. ADD real columns (+ vector-reset MODIFY add-half)
     *   8. ADD virtual columns (covers ADD + the add-half of MODIFY)
     *   9. ADD indexes
     *  10. ADD foreign keys (covers ADD + the add-half of MODIFY)
     *  11. Data backfill (vector zero-fill UPDATEs)
     *  12. CREATE triggers (covers ADD + the create-half of MODIFY) — last so trigger bodies
     *      reference columns in their final shape and backfill UPDATEs do not fire triggers.
     *
     * Per-diff `sql` on each child diff is still rendered for frontend display (it shows the full
     * conceptual operation, e.g. "drop;\nadd" for FK MODIFY). The execution list returned here
     * intentionally splits those into the right phase buckets.
     *
     * @return string[]
     */
    protected function assemblePhaseOrderedStatements(DatabaseModel $databaseModel, DBTableDiff $diff): array
    {
        $tableName = $databaseModel->sqlTableName;
        $statements = [];

        // Phase 0: DROP triggers (DROP + drop-half of MODIFY).
        foreach ($diff->triggerDiffs->getElements() as $triggerDiff) {
            $needsDrop = $triggerDiff->changeKind === DBTriggerDiff::CHANGE_KIND_DROP
                || $triggerDiff->changeKind === DBTriggerDiff::CHANGE_KIND_MODIFY;
            if ($needsDrop) {
                $statements[] = $this->renderTriggerDropSql($triggerDiff->triggerName);
            }
        }

        // Phase 1: DROP FKs (DROP + drop-half of MODIFY).
        foreach ($diff->foreignKeyDiffs->getElements() as $fkDiff) {
            $needsDrop = $fkDiff->changeKind === DBForeignKeyDiff::CHANGE_KIND_DROP
                || $fkDiff->changeKind === DBForeignKeyDiff::CHANGE_KIND_MODIFY;
            if ($needsDrop && $fkDiff->currentConstraintName !== null) {
                $statements[] = $this->renderForeignKeyDropSql($tableName, $fkDiff->currentConstraintName);
            }
        }

        // Phase 2: DROP indexes.
        foreach ($diff->indexDiffs->getElements() as $indexDiff) {
            if ($indexDiff->changeKind !== DBIndexDiff::CHANGE_KIND_DROP) {
                continue;
            }
            if ($indexDiff->currentIndexName !== null) {
                $statements[] = $this->renderIndexDropSql($tableName, $indexDiff->currentIndexName);
            }
        }

        // Phase 3: DROP virtual columns (DROP + drop-half of MODIFY — MySQL cannot ALTER a generation expression).
        foreach ($diff->virtualColumnDiffs->getElements() as $vcDiff) {
            $needsDrop = $vcDiff->changeKind === DBVirtualColumnDiff::CHANGE_KIND_DROP
                || $vcDiff->changeKind === DBVirtualColumnDiff::CHANGE_KIND_MODIFY;
            if ($needsDrop) {
                $statements[] = $this->renderVirtualColumnDropSql($tableName, $vcDiff->columnName);
            }
        }

        // Phase 4: DROP real columns. Includes MODIFY-with-requiresFullReset (vector rebuilds),
        // whose drop+add halves are placed in phases 4 + 7 like other unalterable changes.
        foreach ($diff->columnDiffs->getElements() as $columnDiff) {
            $isReset = $columnDiff->changeKind === DBColumnDiff::CHANGE_KIND_MODIFY
                && $columnDiff->requiresFullReset;
            if ($columnDiff->changeKind === DBColumnDiff::CHANGE_KIND_DROP || $isReset) {
                $statements[] = $this->renderColumnDropSql($tableName, $columnDiff->columnName);
            }
        }

        // Phase 5: collation change.
        if ($diff->collationChange !== null) {
            $statements[] = sprintf(
                "ALTER TABLE `%s` COLLATE = '%s'",
                $tableName,
                $databaseModel->collation
            );
        }

        // Phase 6: MODIFY real columns (single in-place statement). Excludes requiresFullReset
        // diffs — those are rebuilt via DROP+ADD across phases 4 and 7.
        foreach ($diff->columnDiffs->getElements() as $columnDiff) {
            if ($columnDiff->changeKind !== DBColumnDiff::CHANGE_KIND_MODIFY) {
                continue;
            }
            if ($columnDiff->requiresFullReset) {
                continue;
            }
            if ($columnDiff->targetColumn !== null) {
                $statements[] = $this->renderColumnModifySql($tableName, $columnDiff->targetColumn);
            }
        }

        // Phase 7: ADD real columns. Includes MODIFY-with-requiresFullReset (add-half of the rebuild).
        foreach ($diff->columnDiffs->getElements() as $columnDiff) {
            $isResetAdd = $columnDiff->changeKind === DBColumnDiff::CHANGE_KIND_MODIFY
                && $columnDiff->requiresFullReset;
            $isPlainAdd = $columnDiff->changeKind === DBColumnDiff::CHANGE_KIND_ADD;
            if (($isPlainAdd || $isResetAdd) && $columnDiff->targetColumn !== null) {
                $statements[] = $this->renderColumnAddSql($tableName, $columnDiff->targetColumn);
            }
        }

        // Phase 8: ADD virtual columns (ADD + add-half of MODIFY).
        foreach ($diff->virtualColumnDiffs->getElements() as $vcDiff) {
            $needsAdd = $vcDiff->changeKind === DBVirtualColumnDiff::CHANGE_KIND_ADD
                || $vcDiff->changeKind === DBVirtualColumnDiff::CHANGE_KIND_MODIFY;
            if ($needsAdd && $vcDiff->targetVirtualColumn !== null) {
                $statements[] = $this->renderVirtualColumnAddSql($tableName, $vcDiff->targetVirtualColumn);
            }
        }

        // Phase 9: ADD indexes.
        foreach ($diff->indexDiffs->getElements() as $indexDiff) {
            if ($indexDiff->changeKind === DBIndexDiff::CHANGE_KIND_ADD && $indexDiff->targetIndex !== null) {
                $statements[] = $this->renderIndexAddSql($tableName, $indexDiff->targetIndex);
            }
        }

        // Phase 10: ADD FKs (ADD + add-half of MODIFY).
        // On MODIFY, reuse the live constraint name (captured as currentConstraintName) so the
        // re-created FK keeps the same identifier as the dropped one — pt-osc's cosmetic `_`
        // prefix is preserved, breaking the rename ping-pong that would otherwise re-introduce
        // the diff on every cycle. Pure ADD has no current, so the default synthesised name applies.
        foreach ($diff->foreignKeyDiffs->getElements() as $fkDiff) {
            $needsAdd = $fkDiff->changeKind === DBForeignKeyDiff::CHANGE_KIND_ADD
                || $fkDiff->changeKind === DBForeignKeyDiff::CHANGE_KIND_MODIFY;
            if ($needsAdd && $fkDiff->targetForeignKey !== null) {
                $nameOverride = $fkDiff->changeKind === DBForeignKeyDiff::CHANGE_KIND_MODIFY
                    ? (string)($fkDiff->currentConstraintName ?? '')
                    : null;
                $statements[] = $this->renderForeignKeyAddSql($tableName, $fkDiff->targetForeignKey, $nameOverride !== '' ? $nameOverride : null);
            }
        }

        // Phase 11: data backfill. Runs after every schema change so the affected columns are in
        // their final shape. Currently used for VECTOR zero-fills (set on ADD or vector-reset
        // MODIFY of a VECTOR column). Other future backfills (e.g. defaulting a new NOT NULL
        // column on a populated table) would slot in here.
        foreach ($diff->columnDiffs->getElements() as $columnDiff) {
            if ($columnDiff->resetSql !== null && $columnDiff->resetSql !== '') {
                $statements[] = $columnDiff->resetSql;
            }
        }

        // Phase 12: CREATE triggers (ADD + add-half of MODIFY). Runs last so trigger bodies
        // reference columns in their final shape — and the backfill UPDATEs in phase 11 fire
        // without triggers, which is the desired behaviour for zero-filling fresh vector columns.
        foreach ($diff->triggerDiffs->getElements() as $triggerDiff) {
            $needsCreate = $triggerDiff->changeKind === DBTriggerDiff::CHANGE_KIND_ADD
                || $triggerDiff->changeKind === DBTriggerDiff::CHANGE_KIND_MODIFY;
            if ($needsCreate && $triggerDiff->targetSql !== null && $triggerDiff->targetSql !== '') {
                $statements[] = $triggerDiff->targetSql;
            }
        }

        return $statements;
    }

    /**
     * Builds a synthetic table diff for a live table that no longer exists in the target
     * (entity-derived) schema. Marked `DROP_TABLE` + `DESTRUCTIVE`; the `entityClassWithNamespace`
     * field stays null because there is no source entity.
     */
    protected function buildDropTableDiff(string $tableName): DBTableDiff
    {
        $diff = new DBTableDiff();
        $diff->sqlTableName = $tableName;
        $diff->changeType = DBTableDiff::CHANGE_TYPE_DROP_TABLE;
        $diff->severity = DBTableDiff::SEVERITY_DESTRUCTIVE;
        $statement = "DROP TABLE `$tableName`";
        $diff->sql = $statement . ';';
        $diff->sqlStatements = [$statement];
        return $diff;
    }

    // ----- SQL render helpers ------------------------------------------------------------------

    protected function renderColumnAddSql(string $tableName, DatabaseColumn $col): string
    {
        return sprintf('ALTER TABLE `%s` %s', $tableName, (string)$col->getSql(true));
    }

    protected function renderColumnDropSql(string $tableName, string $columnName): string
    {
        return sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $tableName, $columnName);
    }

    protected function renderColumnModifySql(string $tableName, DatabaseColumn $col): string
    {
        // DatabaseColumn::getSql(true) prefixes with "ADD COLUMN IF NOT EXISTS ". Strip that for MODIFY.
        $columnSql = (string)preg_replace(
            '/^ADD COLUMN IF NOT EXISTS /',
            '',
            (string)$col->getSql(true)
        );
        return sprintf('ALTER TABLE `%s` MODIFY COLUMN %s', $tableName, $columnSql);
    }

    protected function renderIndexAddSql(string $tableName, DatabaseIndex $idx): string
    {
        // DatabaseIndex::getSql returns a CREATE INDEX statement (not ALTER TABLE), executable standalone.
        return $idx->getSql($tableName);
    }

    protected function renderIndexDropSql(string $tableName, string $indexName): string
    {
        return sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $tableName, $indexName);
    }

    /**
     * @param string $tableName
     * @param DatabaseForeignKey $fk
     * @param string|null $constraintNameOverride When set, used in place of the default
     *     `fk_{table}_{column}` name. The diff service forwards the live constraint name on
     *     MODIFY so pt-osc's cosmetic `_` prefix is preserved across diff cycles.
     */
    protected function renderForeignKeyAddSql(string $tableName, DatabaseForeignKey $fk, ?string $constraintNameOverride = null): string
    {
        return sprintf('ALTER TABLE `%s` %s', $tableName, $fk->getSql($tableName, $constraintNameOverride));
    }

    protected function renderForeignKeyDropSql(string $tableName, string $constraintName): string
    {
        return sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $tableName, $constraintName);
    }

    protected function renderVirtualColumnAddSql(string $tableName, DatabaseVirtualColumn $vc): string
    {
        return sprintf('ALTER TABLE `%s` %s', $tableName, $vc->getSql(true));
    }

    protected function renderVirtualColumnDropSql(string $tableName, string $columnName): string
    {
        return sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $tableName, $columnName);
    }

    protected function isVectorColumn(?DatabaseColumn $col): bool
    {
        return $col !== null && strtoupper((string)$col->sqlType) === DatabaseColumn::SQL_TYPE_VECTOR;
    }

    /**
     * Builds an UPDATE that zero-fills a VECTOR column with a vector of the target dimensionality.
     *
     * MariaDB stores VECTOR values as opaque binary tagged with their dimension. When the
     * dimension changes, the stored bytes are no longer valid and the column must be repopulated.
     * Zero is a defensible default: it is well-defined for cosine/euclidean distance metrics, and
     * it signals "no embedding yet" to downstream code that already needs to handle missing
     * embeddings.
     *
     * No WHERE clause — every row is reset. Fresh ADD on a populated table starts the column at
     * NULL on every row, so resetting all rows produces the desired result; for ALTER-rebuild the
     * underlying values were lost anyway with the DROP.
     */
    protected function renderVectorResetSql(string $tableName, string $columnName, int $dimensions): string
    {
        $dimensions = max(1, $dimensions);
        $zeros = implode(',', array_fill(0, $dimensions, '0'));
        return sprintf(
            "UPDATE `%s` SET `%s` = VEC_FromText('[%s]')",
            $tableName,
            $columnName,
            $zeros
        );
    }

    /**
     * Triggers live in the schema namespace (not the table namespace), so DROP TRIGGER never
     * takes a table reference. IF EXISTS keeps reruns idempotent.
     */
    protected function renderTriggerDropSql(string $triggerName): string
    {
        return sprintf('DROP TRIGGER IF EXISTS `%s`', $triggerName);
    }

    // ----- canonical target shapes --------------------------------------------------------------

    /**
     * @return array<string, DBCanonicalColumn> Keyed by column name.
     */
    protected function canonicaliseTargetColumns(DatabaseModel $databaseModel): array
    {
        $columns = [];
        foreach ($databaseModel->columns->getElements() as $col) {
            if ($col->ignoreProperty) {
                continue;
            }
            $columns[$col->name] = $this->canonicaliseTargetColumn($col);
        }
        return $columns;
    }

    /**
     * Converts a rich target-side {@see DatabaseColumn} (DDD attribute object) into the
     * comparator-friendly {@see DBCanonicalColumn} shape. This is half of the diff's
     * "both sides converge on canonical" contract — the other half is
     * {@see DatabaseSchemaIntrospectionService::buildColumn()}. Any field added to
     * {@see DBCanonicalColumn} must be filled here too.
     */
    protected function canonicaliseTargetColumn(DatabaseColumn $col): DBCanonicalColumn
    {
        // sqlType — uppercase, normalise VARCHAR/VECTOR which carry sizing.
        $sqlType = strtoupper((string)$col->sqlType);

        $length = null;
        $vectorDimensions = null;
        if ($sqlType === DatabaseColumn::SQL_TYPE_VARCHAR) {
            $length = $col->varCharLength;
        } elseif ($sqlType === DatabaseColumn::SQL_TYPE_VECTOR) {
            $vectorDimensions = $col->vectorDimensions;
        }

        $column = new DBCanonicalColumn();
        $column->name = $col->name;
        $column->sqlType = $sqlType;
        $column->length = $length;
        $column->vectorDimensions = $vectorDimensions;
        $column->allowsNull = (bool)$col->allowsNull;
        $column->isUnsigned = (bool)$col->isUnsigned && in_array(
            $sqlType,
            [DatabaseColumn::SQL_TYPE_INT, DatabaseColumn::SQL_TYPE_BIGINT],
            true
        );
        $column->hasAutoIncrement = (bool)$col->hasAutoIncrement;
        $column->defaultValue = $this->renderTargetDefault($col);
        // DDD cannot express SQL-expression defaults — target side is always literal/null.
        $column->defaultIsExpression = false;
        $column->isGenerated = false;
        $column->generationExpression = null;
        $column->isStored = null;
        return $column;
    }

    /**
     * Replicates the default-value branch in {@see DatabaseColumn::getSql()} so target and current
     * agree on the string MySQL would store in COLUMN_DEFAULT.
     */
    protected function renderTargetDefault(DatabaseColumn $col): ?string
    {
        if (isset($col->sqlDefaultValue)) {
            if (is_bool($col->sqlDefaultValue)) {
                return $col->sqlDefaultValue ? '1' : '0';
            }
            return (string)$col->sqlDefaultValue;
        }
        // DDD emits "DEFAULT NULL" for nullable columns without an explicit default. MySQL stores
        // that as COLUMN_DEFAULT=NULL — same as "no default at all", so both sides end up null.
        return null;
    }

    /**
     * @return array<string, DBCanonicalColumn> Keyed by virtual column name.
     */
    protected function canonicaliseTargetVirtualColumns(DatabaseModel $databaseModel): array
    {
        $virtualColumns = [];
        foreach ($databaseModel->virtualColumns->getElements() as $vc) {
            $virtualColumns[$vc->getName()] = $this->canonicaliseTargetVirtualColumn($vc);
        }
        return $virtualColumns;
    }

    /**
     * Target-side counterpart to {@see DatabaseSchemaIntrospectionService::buildVirtualColumn()}.
     * Note: virtual-column `sqlType` comparison is deliberately disabled in the comparator (see
     * §G of the normalisation rules in the schema-diff skill) — the underlying column's getSqlType()
     * adds a length suffix that introspection strips, so a string compare would always mismatch.
     */
    protected function canonicaliseTargetVirtualColumn(DatabaseVirtualColumn $vc): DBCanonicalColumn
    {
        // Reference column's sqlType carries length suffix for VARCHAR — not used in the
        // virtual-column comparison anyway (see compareCanonicalVirtualColumns). Stored bare so
        // the VO shape matches the live-side build.
        $sqlType = strtoupper($vc->referenceColumn?->sqlType ?? '');

        $column = new DBCanonicalColumn();
        $column->name = $vc->getName();
        $column->sqlType = $sqlType;
        $column->isGenerated = true;
        $column->generationExpression = $this->introspectionService->normaliseGenerationExpression($vc->as);
        $column->isStored = $vc->stored;
        return $column;
    }

    /**
     * @return array<string, DBCanonicalIndex> Keyed by match key (indexType + columns).
     */
    protected function canonicaliseTargetIndexes(DatabaseModel $databaseModel): array
    {
        $indexes = [];
        foreach ($databaseModel->indexes->getElements() as $idx) {
            $key = $this->buildIndexMatchKey($idx->indexType, $idx->indexColumns);
            $canonical = new DBCanonicalIndex();
            $canonical->matchKey = $key;
            $canonical->indexType = $idx->indexType;
            $canonical->indexColumns = $idx->indexColumns;
            $canonical->indexName = null; // target side: name is derived at SQL-render time, not tracked here.
            $indexes[$key] = $canonical;
        }
        return $indexes;
    }

    /**
     * @return array<string, DBCanonicalForeignKey> Keyed by match key (FK semantic identity).
     */
    protected function canonicaliseTargetForeignKeys(DatabaseModel $databaseModel): array
    {
        $foreignKeys = [];
        foreach ($databaseModel->foreignKeys->getElements() as $fk) {
            if (!$fk->applyForeignKeyConstraint) {
                continue;
            }
            $key = $this->buildForeignKeyMatchKey(
                $fk->internalIdColumn,
                $fk->foreignTable,
                $fk->foreignIdColumn
            );
            $canonical = new DBCanonicalForeignKey();
            $canonical->matchKey = $key;
            $canonical->internalIdColumn = $fk->internalIdColumn;
            $canonical->foreignTable = $fk->foreignTable;
            $canonical->foreignIdColumn = $fk->foreignIdColumn;
            $canonical->onUpdateAction = $fk->onUpdateAction;
            $canonical->onDeleteAction = $fk->onDeleteAction;
            $canonical->constraintName = null; // target side: not yet named.
            $foreignKeys[$key] = $canonical;
        }
        return $foreignKeys;
    }

    // ----- match keys ---------------------------------------------------------------------------

    /**
     * @param string[] $columns
     */
    protected function buildIndexMatchKey(string $indexType, array $columns): string
    {
        return $indexType . '|' . implode(',', $columns);
    }

    /**
     * Composes the match key used to pair target-side and live-side foreign keys —
     * `(internalIdColumn, foreignTable, foreignIdColumn)`. Mirrors
     * {@see DatabaseSchemaIntrospectionService::buildForeignKeyMatchKey()} byte-for-byte; the two
     * sides MUST stay in lockstep or every FK will look like ADD+DROP.
     */
    protected function buildForeignKeyMatchKey(
        string $internalIdColumn,
        string $foreignTable,
        string $foreignIdColumn
    ): string {
        return "$internalIdColumn->$foreignTable.$foreignIdColumn";
    }

    // ----- columns ------------------------------------------------------------------------------

    /**
     * @param array<string, DBCanonicalColumn> $target
     * @param array<string, DBCanonicalColumn> $current
     */
    protected function diffColumns(DatabaseModel $databaseModel, array $target, array $current): DBColumnDiffs
    {
        $tableName = $databaseModel->sqlTableName;
        $diffs = new DBColumnDiffs();
        foreach ($target as $name => $t) {
            if (isset($current[$name])) {
                continue;
            }
            $diff = new DBColumnDiff();
            $diff->columnName = $name;
            $diff->changeKind = DBColumnDiff::CHANGE_KIND_ADD;
            $diff->targetColumn = $databaseModel->columns->getColumnByName($name);
            $diff->sql = $diff->targetColumn !== null
                ? $this->renderColumnAddSql($tableName, $diff->targetColumn)
                : '';
            // Fresh ADD of a VECTOR column on a populated nullable table leaves every row at NULL.
            // Schedule a zero-vector backfill so downstream search code never sees undefined embeddings.
            if ($this->isVectorColumn($diff->targetColumn)) {
                $diff->resetSql = $this->renderVectorResetSql(
                    $tableName,
                    $name,
                    $diff->targetColumn->vectorDimensions ?? 0
                );
                $diff->sql .= ";\n" . $diff->resetSql;
            }
            $tmp = $diff;
            $diffs->add($tmp);
        }
        foreach ($current as $name => $c) {
            if (isset($target[$name])) {
                continue;
            }
            $diff = new DBColumnDiff();
            $diff->columnName = $name;
            $diff->changeKind = DBColumnDiff::CHANGE_KIND_DROP;
            $diff->currentDefinition = $c;
            $diff->sql = $this->renderColumnDropSql($tableName, $name);
            $tmp = $diff;
            $diffs->add($tmp);
        }
        foreach ($target as $name => $t) {
            if (!isset($current[$name])) {
                continue;
            }
            $changedAttributes = $this->compareCanonicalColumns($t, $current[$name]);
            if (!$changedAttributes) {
                continue;
            }
            $diff = new DBColumnDiff();
            $diff->columnName = $name;
            $diff->changeKind = DBColumnDiff::CHANGE_KIND_MODIFY;
            $diff->targetColumn = $databaseModel->columns->getColumnByName($name);
            $diff->currentDefinition = $current[$name];
            $diff->changedAttributes = $changedAttributes;

            // VECTOR columns cannot be ALTERed in place when the dimensionality or sqlType
            // changes — the underlying binary representation is dimension-tagged. We rebuild the
            // column (drop + add) and zero-fill rather than emit an in-place MODIFY that would
            // fail at execution time. Stays classified as MODIFY conceptually so the frontend
            // shows "column X changed" instead of a confusing drop+add pair.
            $vectorChange = $this->isVectorColumn($diff->targetColumn)
                && (in_array('sqlType', $changedAttributes, true)
                    || in_array('vectorDimensions', $changedAttributes, true));
            if ($vectorChange) {
                $diff->requiresFullReset = true;
                $diff->resetSql = $this->renderVectorResetSql(
                    $tableName,
                    $name,
                    $diff->targetColumn->vectorDimensions ?? 0
                );
                $drop = $this->renderColumnDropSql($tableName, $name);
                $add = $diff->targetColumn !== null
                    ? $this->renderColumnAddSql($tableName, $diff->targetColumn)
                    : '';
                $diff->sql = $add !== ''
                    ? $drop . ";\n" . $add . ";\n" . $diff->resetSql
                    : $drop;
            } else {
                $diff->sql = $diff->targetColumn !== null
                    ? $this->renderColumnModifySql($tableName, $diff->targetColumn)
                    : '';
            }

            $tmp = $diff;
            $diffs->add($tmp);
        }
        return $diffs;
    }

    /**
     * @return string[] List of differing canonical attribute names.
     */
    protected function compareCanonicalColumns(DBCanonicalColumn $target, DBCanonicalColumn $current): array
    {
        $changed = [];
        if (!$this->sqlTypesEqual($target, $current)) {
            $changed[] = 'sqlType';
        }
        if ($target->length !== $current->length) {
            $changed[] = 'length';
        }
        if ($target->vectorDimensions !== $current->vectorDimensions) {
            $changed[] = 'vectorDimensions';
        }
        if ($target->allowsNull !== $current->allowsNull) {
            $changed[] = 'allowsNull';
        }
        if ($target->isUnsigned !== $current->isUnsigned) {
            $changed[] = 'isUnsigned';
        }
        if ($target->hasAutoIncrement !== $current->hasAutoIncrement) {
            $changed[] = 'hasAutoIncrement';
        }
        // Skip default-value comparison when the live side stores an SQL expression as default
        // (CURRENT_TIMESTAMP, UUID(), JSON_OBJECT() etc.) — DDD cannot represent these on the
        // target side. Falling through to defaultValuesEqual() would always report a delta and
        // the apply path would silently strip the expression default in production. The live
        // configuration wins; admins must edit it manually if they want it removed.
        if (!$current->defaultIsExpression
            && !$this->defaultValuesEqual($target->defaultValue, $current->defaultValue)) {
            $changed[] = 'defaultValue';
        }
        return $changed;
    }

    /**
     * BOOLEAN ↔ TINYINT(1) is already normalised by introspection. This remains a single source of
     * truth for any future per-type aliasing (DECIMAL precision, etc.).
     */
    protected function sqlTypesEqual(DBCanonicalColumn $target, DBCanonicalColumn $current): bool
    {
        return $target->sqlType === $current->sqlType;
    }

    /**
     * Compares two `defaultValue` strings for semantic equality. Both `null` and the literal string
     * `"NULL"` collapse to "no default" — MariaDB returns the string `"NULL"` for `DEFAULT NULL`
     * columns where MySQL 8+ returns actual null. Numeric defaults are also compared loosely
     * (`'0'` == `0`) because COLUMN_DEFAULT comes back as a string but DDD may render an int.
     *
     * Function-call defaults (`VEC_FromText(...)`, `UUID()`, …) are normalised via
     * {@see self::normaliseDefaultExpression()} so that case and whitespace formatting differences
     * between target-side rendering and MariaDB's stored form do not produce phantom MODIFY diffs.
     */
    protected function defaultValuesEqual(?string $target, ?string $current): bool
    {
        // Both unset → equal.
        if ($target === null && $current === null) {
            return true;
        }
        if ($target === null || $current === null) {
            return false;
        }
        // Trim surrounding single quotes if MySQL added them (it does not for our shape, but be defensive).
        $t = trim($target, "'");
        $c = trim($current, "'");
        if ($t === $c) {
            return true;
        }
        // Function-call expressions (heuristic: contain a parenthesis). MariaDB stores these with
        // its own formatting — lowercased function names, no whitespace around commas, no outer
        // parens — while DDD renders the original source-style. Normalise both to a canonical form
        // before comparing. Plain literals don't contain parens so they're unaffected.
        if (str_contains($t, '(') || str_contains($c, '(')) {
            return $this->normaliseDefaultExpression($t) === $this->normaliseDefaultExpression($c);
        }
        return false;
    }

    /**
     * Canonicalises a SQL expression default so target-side rendering and MariaDB's stored form
     * compare equal. Same idea as {@see DatabaseSchemaIntrospectionService::normaliseGenerationExpression()}:
     * lowercase, strip backticks, strip one outer pair of parens, collapse whitespace, remove
     * whitespace adjacent to `(` / `)` / `,`. Iteratively extendable as new patterns surface.
     *
     * Example: `(VEC_FromText(CONCAT('[', REPEAT('0,', 1536-1), '0]')))`
     *      and `VEC_FromText(concat('[',repeat('0,',1536 - 1),'0]'))`
     * both fold to: `vec_fromtext(concat('[',repeat('0,',1536-1),'0]'))`.
     */
    protected function normaliseDefaultExpression(string $expression): string
    {
        $value = strtolower($expression);
        $value = str_replace('`', '', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = trim($value);
        // Strip exactly one outer paren pair if it wraps the entire expression.
        if (strlen($value) >= 2 && $value[0] === '(' && $value[-1] === ')'
            && $this->parensBalanceWithoutOuter($value)) {
            $value = substr($value, 1, -1);
            $value = trim($value);
        }
        // Strip whitespace around brackets, commas, and arithmetic/comparison operators so
        // formatting choices like `1536 - 1` vs `1536-1` don't produce phantom diffs.
        $value = preg_replace('/\s*([(),+\-*\/=<>!])\s*/', '$1', $value) ?? $value;
        return $value;
    }

    /**
     * Mirror of {@see DatabaseSchemaIntrospectionService::parensBalanceWithoutOuter()} — used to
     * decide whether the outer parens wrap the entire expression and can be safely stripped.
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
                    return false;
                }
            }
        }
        return $depth === 0;
    }

    // ----- virtual columns ----------------------------------------------------------------------

    /**
     * @param array<string, DBCanonicalColumn> $target
     * @param array<string, DBCanonicalColumn> $current
     */
    protected function diffVirtualColumns(
        DatabaseModel $databaseModel,
        array $target,
        array $current
    ): DBVirtualColumnDiffs {
        $tableName = $databaseModel->sqlTableName;
        $diffs = new DBVirtualColumnDiffs();

        foreach ($target as $name => $t) {
            if (isset($current[$name])) {
                continue;
            }
            $vc = $this->findTargetVirtualColumnByName($databaseModel, $name);
            $diff = new DBVirtualColumnDiff();
            $diff->columnName = $name;
            $diff->changeKind = DBVirtualColumnDiff::CHANGE_KIND_ADD;
            $diff->targetVirtualColumn = $vc;
            $diff->sql = $vc !== null ? $this->renderVirtualColumnAddSql($tableName, $vc) : '';
            $tmp = $diff;
            $diffs->add($tmp);
        }
        foreach ($current as $name => $c) {
            if (isset($target[$name])) {
                continue;
            }
            $diff = new DBVirtualColumnDiff();
            $diff->columnName = $name;
            $diff->changeKind = DBVirtualColumnDiff::CHANGE_KIND_DROP;
            $diff->currentDefinition = $c;
            $diff->sql = $this->renderVirtualColumnDropSql($tableName, $name);
            $tmp = $diff;
            $diffs->add($tmp);
        }
        foreach ($target as $name => $t) {
            if (!isset($current[$name])) {
                continue;
            }
            $changedAttributes = $this->compareCanonicalVirtualColumns($t, $current[$name]);
            if (!$changedAttributes) {
                continue;
            }
            $vc = $this->findTargetVirtualColumnByName($databaseModel, $name);
            $diff = new DBVirtualColumnDiff();
            $diff->columnName = $name;
            $diff->changeKind = DBVirtualColumnDiff::CHANGE_KIND_MODIFY;
            $diff->targetVirtualColumn = $vc;
            $diff->currentDefinition = $current[$name];
            $diff->changedAttributes = $changedAttributes;
            // MySQL cannot ALTER a generation expression — drop + add. The execution path picks
            // these halves apart into phases 3 + 8; the field below is for frontend display only.
            $drop = $this->renderVirtualColumnDropSql($tableName, $name);
            $add = $vc !== null ? $this->renderVirtualColumnAddSql($tableName, $vc) : '';
            $diff->sql = $add !== '' ? $drop . ";\n" . $add : $drop;
            $tmp = $diff;
            $diffs->add($tmp);
        }
        return $diffs;
    }

    /**
     * Lookup helper for virtual columns: name is a stable identifier on both sides (it's declared
     * in the entity property), so name-based matching is safe here — unlike for indexes / FKs.
     */
    protected function findTargetVirtualColumnByName(DatabaseModel $databaseModel, string $name): ?DatabaseVirtualColumn
    {
        foreach ($databaseModel->virtualColumns->getElements() as $vc) {
            if ($vc->getName() === $name) {
                return $vc;
            }
        }
        return null;
    }

    /**
     * Virtual column comparison intentionally ignores sqlType. The DDD-emitted CREATE references
     * the upstream column's `getSqlType()` (which carries a length suffix like `VARCHAR(255)`),
     * while INFORMATION_SCHEMA exposes bare `VARCHAR` plus length separately — direct string
     * comparison would false-positive on every virtual column. The substantive change is captured
     * by generationExpression (the formula MySQL evaluates) and isStored (whether the result is
     * materialised on disk).
     *
     * @return string[]
     */
    protected function compareCanonicalVirtualColumns(DBCanonicalColumn $target, DBCanonicalColumn $current): array
    {
        $changed = [];
        $tExpr = $this->introspectionService->normaliseGenerationExpression($target->generationExpression);
        $cExpr = $this->introspectionService->normaliseGenerationExpression($current->generationExpression);
        if ($tExpr !== $cExpr) {
            $changed[] = 'generationExpression';
        }
        if ($target->isStored !== $current->isStored) {
            $changed[] = 'isStored';
        }
        return $changed;
    }

    // ----- indexes ------------------------------------------------------------------------------

    /**
     * @param array<string, DBCanonicalIndex> $target  Keyed by match key.
     * @param array<string, DBCanonicalIndex> $current Keyed by live INDEX_NAME.
     */
    protected function diffIndexes(DatabaseModel $databaseModel, array $target, array $current): DBIndexDiffs
    {
        $tableName = $databaseModel->sqlTableName;
        $diffs = new DBIndexDiffs();

        // Index live by match key so we can do set-diff symmetrically.
        $currentByMatchKey = [];
        foreach ($current as $c) {
            $currentByMatchKey[$c->matchKey] = $c;
        }

        foreach ($target as $key => $t) {
            if (isset($currentByMatchKey[$key])) {
                continue;
            }
            $idx = $this->findTargetIndexByMatchKey($databaseModel, $key);
            $diff = new DBIndexDiff();
            $diff->matchKey = $key;
            $diff->changeKind = DBIndexDiff::CHANGE_KIND_ADD;
            $diff->targetIndex = $idx;
            $diff->sql = $idx !== null ? $this->renderIndexAddSql($tableName, $idx) : '';
            $tmp = $diff;
            $diffs->add($tmp);
        }
        foreach ($currentByMatchKey as $key => $c) {
            if (isset($target[$key])) {
                continue;
            }
            $diff = new DBIndexDiff();
            $diff->matchKey = $key;
            $diff->changeKind = DBIndexDiff::CHANGE_KIND_DROP;
            $diff->currentIndexName = $c->indexName;
            $diff->currentDefinition = $c;
            $diff->sql = $this->renderIndexDropSql($tableName, (string)$c->indexName);
            $tmp = $diff;
            $diffs->add($tmp);
        }
        // Indexes matched by key cannot differ in (indexType, indexColumns) — that is the key
        // itself. They only "differ" in things like distance metric / max neighbors for VECTOR
        // indexes, which are baked into the CREATE statement. Treat any such difference as
        // DROP + ADD via a MODIFY pseudo-diff (skipped here for v1; non-vector tables hit zero).
        return $diffs;
    }

    /**
     * Index lookup keyed by `(indexType, columns)` rather than name — see
     * {@see DatabaseSchemaIntrospectionService::buildIndexMatchKey()} for the exact composition.
     * Required because index names are auto-generated and differ between target (DDD-emitted) and
     * live (MySQL-assigned) sides.
     */
    protected function findTargetIndexByMatchKey(DatabaseModel $databaseModel, string $matchKey): ?DatabaseIndex
    {
        foreach ($databaseModel->indexes->getElements() as $idx) {
            if ($this->buildIndexMatchKey($idx->indexType, $idx->indexColumns) === $matchKey) {
                return $idx;
            }
        }
        return null;
    }

    // ----- foreign keys -------------------------------------------------------------------------

    /**
     * @param array<string, DBCanonicalForeignKey> $target  Keyed by match key.
     * @param array<string, DBCanonicalForeignKey> $current Keyed by live CONSTRAINT_NAME.
     */
    protected function diffForeignKeys(
        DatabaseModel $databaseModel,
        array $target,
        array $current
    ): DBForeignKeyDiffs {
        $tableName = $databaseModel->sqlTableName;
        $diffs = new DBForeignKeyDiffs();

        $currentByMatchKey = [];
        foreach ($current as $c) {
            $currentByMatchKey[$c->matchKey] = $c;
        }

        foreach ($target as $key => $t) {
            if (isset($currentByMatchKey[$key])) {
                continue;
            }
            $fk = $this->findTargetForeignKeyByMatchKey($databaseModel, $key);
            $diff = new DBForeignKeyDiff();
            $diff->matchKey = $key;
            $diff->changeKind = DBForeignKeyDiff::CHANGE_KIND_ADD;
            $diff->targetForeignKey = $fk;
            $diff->sql = $fk !== null ? $this->renderForeignKeyAddSql($tableName, $fk) : '';
            $tmp = $diff;
            $diffs->add($tmp);
        }
        foreach ($currentByMatchKey as $key => $c) {
            if (isset($target[$key])) {
                continue;
            }
            $diff = new DBForeignKeyDiff();
            $diff->matchKey = $key;
            $diff->changeKind = DBForeignKeyDiff::CHANGE_KIND_DROP;
            $diff->currentConstraintName = $c->constraintName;
            $diff->currentDefinition = $c;
            $diff->sql = $this->renderForeignKeyDropSql($tableName, (string)$c->constraintName);
            $tmp = $diff;
            $diffs->add($tmp);
        }
        foreach ($target as $key => $t) {
            if (!isset($currentByMatchKey[$key])) {
                continue;
            }
            $changedAttributes = $this->compareCanonicalForeignKeys($t, $currentByMatchKey[$key]);
            if (!$changedAttributes) {
                continue;
            }
            $fk = $this->findTargetForeignKeyByMatchKey($databaseModel, $key);
            $diff = new DBForeignKeyDiff();
            $diff->matchKey = $key;
            $diff->changeKind = DBForeignKeyDiff::CHANGE_KIND_MODIFY;
            $diff->targetForeignKey = $fk;
            $diff->currentConstraintName = $currentByMatchKey[$key]->constraintName;
            $diff->currentDefinition = $currentByMatchKey[$key];
            $diff->changedAttributes = $changedAttributes;
            // MySQL needs drop + add for any FK action change. Halves are split into phases 1 + 10
            // by the executor; the field below is for frontend display only.
            //
            // Preserve the live constraint name across MODIFY. Without the override, the ADD half
            // would synthesize the default `fk_{table}_{column}` name, which renames pt-osc-prefixed
            // constraints (`_fk_*`) on every diff cycle — a cosmetic ping-pong that triggers a
            // fresh COPY-forcing diff after every pt-osc run.
            $currentName = (string)$currentByMatchKey[$key]->constraintName;
            $drop = $this->renderForeignKeyDropSql($tableName, $currentName);
            $add = $fk !== null ? $this->renderForeignKeyAddSql($tableName, $fk, $currentName) : '';
            $diff->sql = $add !== '' ? $drop . ";\n" . $add : $drop;
            $tmp = $diff;
            $diffs->add($tmp);
        }
        return $diffs;
    }

    /**
     * Foreign-key lookup keyed by `(internalIdColumn, foreignTable, foreignIdColumn)` rather than
     * constraint name — see {@see self::buildForeignKeyMatchKey()}. Constraint names are
     * auto-generated and differ between sides, so name-based matching would flag every FK as MODIFY.
     */
    protected function findTargetForeignKeyByMatchKey(DatabaseModel $databaseModel, string $matchKey): ?DatabaseForeignKey
    {
        foreach ($databaseModel->foreignKeys->getElements() as $fk) {
            $key = $this->buildForeignKeyMatchKey($fk->internalIdColumn, $fk->foreignTable, $fk->foreignIdColumn);
            if ($key === $matchKey) {
                return $fk;
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    protected function compareCanonicalForeignKeys(
        DBCanonicalForeignKey $target,
        DBCanonicalForeignKey $current
    ): array {
        $changed = [];
        if ($this->normaliseReferentialAction($target->onUpdateAction)
            !== $this->normaliseReferentialAction($current->onUpdateAction)) {
            $changed[] = 'onUpdateAction';
        }
        if ($this->normaliseReferentialAction($target->onDeleteAction)
            !== $this->normaliseReferentialAction($current->onDeleteAction)) {
            $changed[] = 'onDeleteAction';
        }
        return $changed;
    }

    /**
     * Normalises MySQL/MariaDB referential actions for equality comparison.
     *
     * `NO ACTION` and `RESTRICT` are functional synonyms in MySQL/MariaDB — Deferred constraints
     * aren't supported, so `NO ACTION` acts as `RESTRICT` (checked at statement execution time).
     * A column declared `ON UPDATE NO ACTION` and one declared `ON UPDATE RESTRICT` behave
     * identically; treating them as different strings creates phantom MODIFY diffs whenever an
     * entity declares one and the DB stores the other (typical drift after pt-online-schema-change,
     * which preserves the DB's chosen variant verbatim regardless of entity intent).
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html
     *      "For MySQL, NO ACTION is equivalent to RESTRICT … the statement is rejected."
     */
    protected function normaliseReferentialAction(string $action): string
    {
        $upper = strtoupper(trim($action));
        return match ($upper) {
            'NO ACTION' => 'RESTRICT',
            default     => $upper,
        };
    }

    // ----- triggers -----------------------------------------------------------------------------

    /**
     * Computes trigger-level deltas for one table. Triggers cannot be ALTERed in place; any body
     * change is implemented as DROP + CREATE (phases 0 + 12 of the assembler).
     *
     * Matching: target triggers are extracted from DatabaseModel::$triggers by reading each
     * trigger's source .sql file and parsing the CREATE TRIGGER header. They are keyed by trigger
     * name (the same name the live trigger uses). Live triggers come from INFORMATION_SCHEMA.
     * Body comparison runs through DatabaseSchemaIntrospectionService::normaliseTriggerBody on
     * both sides so whitespace, casing and a wrapping BEGIN…END do not falsely flag changes.
     *
     * @param array<string, DBCanonicalTrigger> $currentTriggers Keyed by trigger name.
     * @throws ReflectionException
     */
    protected function diffTriggers(DatabaseModel $databaseModel, array $currentTriggers): DBTriggerDiffs
    {
        $diffs = new DBTriggerDiffs();
        $targetTriggers = $this->canonicaliseTargetTriggers($databaseModel);

        foreach ($targetTriggers as $triggerName => $t) {
            if (isset($currentTriggers[$triggerName])) {
                continue;
            }
            $diff = new DBTriggerDiff();
            $diff->triggerName = $triggerName;
            $diff->tableName = $databaseModel->sqlTableName;
            $diff->changeKind = DBTriggerDiff::CHANGE_KIND_ADD;
            $diff->targetSql = (string)$t->rawSql;
            $diff->sql = $diff->targetSql;
            $tmp = $diff;
            $diffs->add($tmp);
        }
        foreach ($currentTriggers as $triggerName => $c) {
            if (isset($targetTriggers[$triggerName])) {
                continue;
            }
            $diff = new DBTriggerDiff();
            $diff->triggerName = $triggerName;
            $diff->tableName = $databaseModel->sqlTableName;
            $diff->changeKind = DBTriggerDiff::CHANGE_KIND_DROP;
            $diff->currentDefinition = $c;
            $diff->sql = $this->renderTriggerDropSql($triggerName);
            $tmp = $diff;
            $diffs->add($tmp);
        }
        foreach ($targetTriggers as $triggerName => $t) {
            if (!isset($currentTriggers[$triggerName])) {
                continue;
            }
            if ($t->normalisedBody === $currentTriggers[$triggerName]->normalisedBody) {
                continue;
            }
            $diff = new DBTriggerDiff();
            $diff->triggerName = $triggerName;
            $diff->tableName = $databaseModel->sqlTableName;
            $diff->changeKind = DBTriggerDiff::CHANGE_KIND_MODIFY;
            $diff->targetSql = (string)$t->rawSql;
            $diff->currentDefinition = $currentTriggers[$triggerName];
            $diff->sql = $this->renderTriggerDropSql($triggerName) . ";\n" . $diff->targetSql;
            $tmp = $diff;
            $diffs->add($tmp);
        }
        return $diffs;
    }

    /**
     * @return array<string, DBCanonicalTrigger> Keyed by trigger name. Each entry has rawSql + normalisedBody populated.
     * @throws ReflectionException
     */
    protected function canonicaliseTargetTriggers(DatabaseModel $databaseModel): array
    {
        $triggers = [];
        if ($databaseModel->triggers === null) {
            return $triggers;
        }
        foreach ($databaseModel->triggers->getElements() as $trigger) {
            $rawSql = $trigger->getSql($databaseModel->entityClassWithNamespace);
            if ($rawSql === '') {
                continue;
            }
            foreach ($this->extractCreateTriggerStatements($rawSql) as $extracted) {
                $canonical = new DBCanonicalTrigger();
                $canonical->triggerName = $extracted['triggerName'];
                $canonical->tableName = $databaseModel->sqlTableName;
                $canonical->rawSql = $extracted['rawSql'];
                $canonical->normalisedBody = $this->introspectionService->normaliseTriggerBody($extracted['body']);
                $triggers[$canonical->triggerName] = $canonical;
            }
        }
        return $triggers;
    }

    /**
     * Parses one or more CREATE TRIGGER statements out of a target SQL blob. We keep this lenient:
     * the framework convention is one trigger per .sql file, but the user may have multiple. We
     * return each one with its name, full statement (for re-emission) and body (for comparison).
     *
     * @return array<int,array{triggerName:string,rawSql:string,body:string}>
     */
    protected function extractCreateTriggerStatements(string $sql): array
    {
        $pattern = '/CREATE\s+(?:OR\s+REPLACE\s+)?(?:DEFINER\s*=\s*\S+\s+)?TRIGGER\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z_][A-Za-z0-9_]*)`?'
            . '\s+(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+`?[A-Za-z_][A-Za-z0-9_]*`?'
            . '\s+FOR\s+EACH\s+ROW\s+(.+?)(?=(?:\s*CREATE\s+(?:OR\s+REPLACE\s+)?(?:DEFINER\s*=\s*\S+\s+)?TRIGGER)|\z)/is';
        $matches = [];
        if (!preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $results = [];
        foreach ($matches as $match) {
            $results[] = [
                'triggerName' => $match[1],
                'rawSql'      => rtrim(trim($match[0]), ';'),
                'body'        => trim($match[2]),
            ];
        }
        return $results;
    }

    // ----- severity -----------------------------------------------------------------------------

    /**
     * ADDITIVE = pure ADDs and widening tweaks (the diff can be applied without data loss risk).
     * DESTRUCTIVE = any DROP or narrowing.
     * MIXED = combination of both.
     */
    protected function classifySeverity(DBTableDiff $diff): string
    {
        $hasDestructive = false;
        $hasAdditive = false;

        foreach ($diff->columnDiffs->getElements() as $columnDiff) {
            if ($columnDiff->changeKind === DBColumnDiff::CHANGE_KIND_DROP) {
                $hasDestructive = true;
            } elseif ($columnDiff->changeKind === DBColumnDiff::CHANGE_KIND_MODIFY) {
                // Narrowing nullability or shrinking length is destructive; otherwise additive.
                if ($this->columnModifyIsDestructive($columnDiff)) {
                    $hasDestructive = true;
                } else {
                    $hasAdditive = true;
                }
            } else {
                $hasAdditive = true;
            }
        }
        foreach ($diff->virtualColumnDiffs->getElements() as $vcDiff) {
            if ($vcDiff->changeKind === DBVirtualColumnDiff::CHANGE_KIND_DROP
                || $vcDiff->changeKind === DBVirtualColumnDiff::CHANGE_KIND_MODIFY) {
                $hasDestructive = true; // virtual column MODIFY is drop+add → data rebuilt
            } else {
                $hasAdditive = true;
            }
        }
        foreach ($diff->indexDiffs->getElements() as $indexDiff) {
            if ($indexDiff->changeKind === DBIndexDiff::CHANGE_KIND_DROP) {
                $hasDestructive = true;
            } else {
                $hasAdditive = true;
            }
        }
        foreach ($diff->foreignKeyDiffs->getElements() as $fkDiff) {
            if ($fkDiff->changeKind === DBForeignKeyDiff::CHANGE_KIND_DROP
                || $fkDiff->changeKind === DBForeignKeyDiff::CHANGE_KIND_MODIFY) {
                $hasDestructive = true;
            } else {
                $hasAdditive = true;
            }
        }
        foreach ($diff->triggerDiffs->getElements() as $triggerDiff) {
            // DROP removes existing behaviour; MODIFY swaps the trigger body which could change
            // semantics in subtle ways — both warrant explicit operator confirmation.
            if ($triggerDiff->changeKind === DBTriggerDiff::CHANGE_KIND_DROP
                || $triggerDiff->changeKind === DBTriggerDiff::CHANGE_KIND_MODIFY) {
                $hasDestructive = true;
            } else {
                $hasAdditive = true;
            }
        }

        if ($hasDestructive && $hasAdditive) {
            return DBTableDiff::SEVERITY_MIXED;
        }
        if ($hasDestructive) {
            return DBTableDiff::SEVERITY_DESTRUCTIVE;
        }
        return DBTableDiff::SEVERITY_ADDITIVE;
    }

    /**
     * Single source of truth for "is this column-level MODIFY destructive?". Used by both
     * {@see self::classifySeverity()} and the per-column severity badge in the apply UI. Returns
     * true when the MODIFY: drops a default, drops auto-increment, adds NOT NULL on a previously
     * nullable column, shrinks a VARCHAR length, changes sqlType, or requires a VECTOR full reset.
     * Any other change (e.g. unsigned flip, adding a default) is ADDITIVE.
     */
    protected function columnModifyIsDestructive(DBColumnDiff $columnDiff): bool
    {
        $target = $columnDiff->targetColumn;
        $current = $columnDiff->currentDefinition;
        if ($target === null) {
            return true;
        }
        // VECTOR rebuild — column data is replaced with zero vectors. Always destructive.
        if ($columnDiff->requiresFullReset) {
            return true;
        }
        // NOT NULL added on a previously nullable column → existing NULL rows would fail.
        if (
            in_array('allowsNull', $columnDiff->changedAttributes, true)
            && ($current?->allowsNull ?? true) === true
            && (bool)$target->allowsNull === false
        ) {
            return true;
        }
        // VARCHAR shrunk.
        if (
            in_array('length', $columnDiff->changedAttributes, true)
            && strtoupper((string)$target->sqlType) === DatabaseColumn::SQL_TYPE_VARCHAR
            && $current?->length !== null
            && $target->varCharLength < $current->length
        ) {
            return true;
        }
        // Type change other than BOOLEAN equivalence is destructive (CAST risk).
        if (in_array('sqlType', $columnDiff->changedAttributes, true)) {
            return true;
        }
        return false;
    }
}
