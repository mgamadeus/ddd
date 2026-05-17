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
use DDD\Domain\Base\Repo\DB\Database\Diff\DBCollationChange;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBColumnDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBColumnDiffs;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBCopyForcingOperation;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBCopyForcingOperations;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBExpectedDiffSignature;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBExpectedDiffSignatures;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBForeignKeyDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBForeignKeyDiffs;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBIndexDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBIndexDiffs;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBSqlStatements;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiffs;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableSizeStats;
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

        $this->decorateDiffsWithSignals($diffs);

        return $diffs;
    }

    /**
     * Scoped variant of {@see self::computeDiffs()} keyed by physical SQL table names. Used by
     * the apply path's post-execute refresh — we want to recompute diffs ONLY for the tables we
     * just touched, never to scan unrelated live tables (the previous attempt at this scoped by
     * entity classes, which broke DROP_TABLE diffs entirely AND caused phantom DROPs for every
     * unscoped live table — both reported in the round-2 audit).
     *
     * Scoping rules:
     *  - Target side: only DatabaseModels whose sqlTableName is in `$sqlTableNames` are considered.
     *  - Live side: only `$sqlTableNames` are introspected; unscoped live tables are invisible to
     *    this method, so no phantom DROPs.
     *  - A table that exists in `$sqlTableNames` but is in NEITHER target nor live is silently
     *    omitted from the result (it was successfully dropped, nothing left to diff).
     *
     * Caller passes the sqlTableNames of every diff in the just-applied batch — works uniformly
     * for ALTER/CREATE/DROP because the table name is the stable identity, even for diffs whose
     * `entityClassWithNamespace` is null (DROP_TABLE).
     *
     * @param string[] $sqlTableNames
     * @throws Exception
     * @throws ReflectionException
     */
    public function computeDiffsForTables(array $sqlTableNames): DBTableDiffs
    {
        $diffs = new DBTableDiffs();
        if ($sqlTableNames === []) {
            return $diffs;
        }
        $scopeSet = array_fill_keys($sqlTableNames, true);

        $databaseModels = EntityModelGeneratorService::getDatabaseModels();
        $liveTableNames = $this->introspectionService->getLiveTableNames();
        $ignoredLiveTables = $this->getIgnoredLiveTables();

        $targetByTableName = [];
        foreach ($databaseModels->getElements() as $databaseModel) {
            if ($databaseModel->parentEntityCLassWithNamespace !== null) {
                continue;
            }
            if (!isset($scopeSet[$databaseModel->sqlTableName])) {
                continue;
            }
            $targetByTableName[$databaseModel->sqlTableName] = $databaseModel;
        }

        foreach ($sqlTableNames as $tableName) {
            $databaseModel = $targetByTableName[$tableName] ?? null;
            $isLive = in_array($tableName, $liveTableNames, true);

            if ($databaseModel === null && !$isLive) {
                // Table is gone (just dropped, no entity declares it). Nothing to diff — the
                // caller's apply succeeded and there's no pending state for this name.
                continue;
            }
            if ($databaseModel === null) {
                // Live-only AND in scope → DROP_TABLE candidate. Honour the ignored list so the
                // refresh doesn't accidentally produce a DROP for an explicitly-ignored table the
                // operator passed in.
                if (in_array($tableName, $ignoredLiveTables, true)) {
                    continue;
                }
                $dropDiff = $this->buildDropTableDiff($tableName);
                $diffs->add($dropDiff);
                continue;
            }
            $current = $isLive
                ? $this->introspectionService->introspectTable($tableName)
                : null;
            $tableDiff = $this->computeTableDiff($databaseModel, $current);
            if ($tableDiff->changeType === DBTableDiff::CHANGE_TYPE_NO_CHANGE) {
                continue;
            }
            $diffs->add($tableDiff);
        }

        $this->decorateDiffsWithSignals($diffs);

        return $diffs;
    }

    /**
     * Runs the production-guard decorator + signature computation on every diff in the set. Shared
     * between {@see self::computeDiffs()} and {@see self::computeDiffsForTables()} so both paths
     * produce identically-shaped diffs (signature covers all operator-visible fields). Signature
     * MUST be computed AFTER decoration so it hashes the populated tableSizeMb / directApplyBlocked
     * fields — see {@see self::computeDiffSignature()}.
     */
    protected function decorateDiffsWithSignals(DBTableDiffs $diffs): void
    {
        foreach ($diffs->getElements() as $diff) {
            $this->populateProductionGuardSignal($diff);
            $diff->diffSignature = $this->computeDiffSignature($diff);
        }
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
        $diff->tableSizeMb = $stats?->sizeMb;
        $diff->tableRowCount = $stats?->rowCount;

        if ($stats === null || !$stats->isLarge(self::LARGE_TABLE_SIZE_THRESHOLD_MB, self::LARGE_TABLE_ROW_THRESHOLD)) {
            return;
        }

        // DROP TABLE on a large Galera-replicated table is also COPY-forcing in effect — the metadata
        // lock + binlog write blocks the whole cluster for the duration of the file unlink. The
        // detectCopyForcingOperations() inspector only covers ALTER paths (column MODIFY, virtual
        // column MODIFY, FULLTEXT ADD); without this branch, a 5 GB DROP TABLE would be one operator
        // click away with only the destructive-severity confirm in between. Treat it as its own
        // copy-forcing op so the same block path applies.
        if ($diff->changeType === DBTableDiff::CHANGE_TYPE_DROP_TABLE) {
            $diff->directApplyBlocked = true;
            $diff->copyForcingOperations = DBCopyForcingOperations::fromDescriptionList([
                "DROP TABLE on large table (size $diff->tableSizeMb MB / $diff->tableRowCount rows) — Galera TOI block during file unlink + binlog write",
            ]);
            $diff->directApplyBlockReason = $this->buildProductionGuardMessage(
                $diff->sqlTableName,
                $diff->tableSizeMb,
                $diff->tableRowCount,
                $diff->copyForcingOperations
            );
            return;
        }

        $risky = $this->detectCopyForcingOperations($diff);
        if ($risky->count() === 0) {
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
     */
    protected function buildProductionGuardMessage(
        string $tableName,
        ?int $sizeMb,
        ?int $rowCount,
        DBCopyForcingOperations $riskyOperations
    ): string {
        $sizeThreshold = self::LARGE_TABLE_SIZE_THRESHOLD_MB;
        $rowThreshold = self::LARGE_TABLE_ROW_THRESHOLD;
        $sizeShown = $sizeMb !== null ? "{$sizeMb} MB" : 'unknown MB';
        $rowsShown = $rowCount !== null ? number_format($rowCount) . ' rows' : 'unknown rows';
        $offending = "    • " . implode("\n    • ", $riskyOperations->toDescriptionList());
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
    /** @noinspection PhpInconsistentReturnPointsInspection — every reachable path in the outer try
     *  either returns ($this->computeDiffsForTables) or throws (BadRequestException / Doctrine
     *  Exception). The static analyzer doesn't model try/finally control flow precisely enough. */
    public function applyDiffs(
        DBTableDiffs $diffs,
        bool $disableForeignKeyChecks = true,
        bool $bypassProductionGuard = false,
        ?DBExpectedDiffSignatures $expectedDiffSignatures = null
    ): DBTableDiffs {
        $connection = EntityManagerFactory::getInstance()->getConnection();

        // Cross-request mutex: only one apply may run at a time. Closes the concurrent-apply race
        // where two admins (or admin + CLI) both pass the signature gate against the same pre-
        // apply live state and then race to execute. GET_LOCK is a MySQL/MariaDB session-bound
        // advisory lock — held until RELEASE_LOCK or session end, NOT scoped to a transaction.
        // 30-second timeout is enough for any normal apply; if it expires the operator gets a
        // clean 503-shaped error and can retry. Lock name is global to the package so a single
        // misbehaving caller can't break the gate by holding a per-table lock.
        $lockTimeoutSeconds = 30;
        $lockAcquired = (int)$connection->fetchOne(
            'SELECT GET_LOCK(?, ?)',
            [self::APPLY_LOCK_NAME, $lockTimeoutSeconds]
        );
        if ($lockAcquired !== 1) {
            throw new BadRequestException(
                self::ERROR_CODE_APPLY_LOCK_BUSY
                . ' Another schema-diff apply is currently in progress. Wait a few seconds and retry.'
            );
        }

        // Affected table names (used by the targeted refresh below). Keyed by sqlTableName —
        // works uniformly for ALTER/CREATE/DROP because the table name is the stable identity
        // (DROP_TABLE diffs have entityClassWithNamespace === null, so an entity-class-scoped
        // refresh would silently lose them — see round-2 audit).
        $affectedTableNames = [];
        foreach ($diffs->getElements() as $diff) {
            $affectedTableNames[] = $diff->sqlTableName;
        }

        try {
            // CONCURRENCY GATE (round-3 audit fix):
            // When the caller provided expected signatures (HTTP path), the caller's $diffs are
            // a SNAPSHOT computed before this method was even entered — potentially before any
            // other admin's concurrent apply landed and was released. Trusting that snapshot to
            // drive execution would let a second caller re-run already-applied SQL the moment the
            // first releases the lock (their stale $diff->diffSignature == their stale
            // $expected[T], so assertDiffSignaturesMatch on the snapshot itself is tautological).
            //
            // Fix: inside the lock, recompute fresh against the live DB. The caller's $diffs is
            // treated only as "scope by table name" — the actual signature check and execution
            // both use the fresh set. If anything moved (concurrent apply, entity edit, drift),
            // the fresh signature won't match the expected one and we short-circuit cleanly.
            //
            // CLI/messenger callers pass $expected = null and keep the trust-the-caller path
            // unchanged — they're authoritative and the snapshot they hold IS the intent.
            //
            // Both caches must be invalidated FIRST:
            //   1. The introspection cache (live DB state) — the caller's earlier computeDiffs()
            //      in the same HTTP request already populated it. Without invalidation the fresh
            //      recompute re-uses the cached canonical tables — the very state we're trying
            //      to verify against — and the gate would be tautological.
            //   2. The EntityModelGenerator static cache (target-derived models) — a process-
            //      level cache that's NOT scoped per request. In dev with PHP-FPM, two requests
            //      on the same worker see the SAME cached models even if an entity file was
            //      edited between them, producing a stale TARGET side that doesn't match the
            //      live INFORMATION_SCHEMA the introspection just re-read. The user-visible
            //      symptom is a perpetual signature mismatch where the frontend's captured
            //      diff and the backend's recomputed diff disagree because they were generated
            //      from different entity reflection snapshots.
            if ($expectedDiffSignatures !== null) {
                $this->introspectionService->invalidateCache();
                EntityModelGeneratorService::invalidateCache();
                $diffs = $this->computeDiffsForTables($affectedTableNames);
            }
            $this->assertDiffSignaturesMatch($diffs, $expectedDiffSignatures);
            if (!$bypassProductionGuard) {
                foreach ($diffs->getElements() as $diff) {
                    $this->assertSafeForDirectApply($diff);
                }
            }
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
            // Targeted refresh: recompute only the tables we just touched (by name, never by
            // entity class — see comment above for why). Does NOT scan the rest of the live
            // schema, so unrelated tables can't accidentally show up as phantom DROP_TABLE
            // diffs (the regression the round-2 audit caught in the entity-class-scoped path).
            return $this->computeDiffsForTables($affectedTableNames);
        } finally {
            $connection->executeStatement('SELECT RELEASE_LOCK(?)', [self::APPLY_LOCK_NAME]);
        }
    }

    /**
     * Connection-bound advisory lock name. Global because we want one apply at a time *across*
     * tables — partial overlap between two diff-sets would otherwise risk inconsistent
     * intermediate state.
     */
    public const string APPLY_LOCK_NAME = 'ddd_schema_diff_apply';

    /**
     * Returned as the error-code prefix when the apply lock can't be acquired within the
     * timeout. Frontends should match on this prefix (substring) to render a "try again"
     * affordance distinct from the signature-mismatch one.
     */
    public const string ERROR_CODE_APPLY_LOCK_BUSY = '[DIFF_APPLY_LOCK_BUSY]';

    /**
     * Applies a single table's diff. Same FK-check handling as applyDiffs.
     *
     * @throws Exception
     * @throws ReflectionException
     * @throws BadRequestException When `$expectedDiffSignature` is set and the freshly-computed
     *         diff's signature differs from it (drift between view and apply).
     */
    public function applyDiff(
        DBTableDiff $diff,
        bool $disableForeignKeyChecks = true,
        bool $bypassProductionGuard = false,
        ?string $expectedDiffSignature = null
    ): DBTableDiffs {
        $set = new DBTableDiffs();
        $set->add($diff);
        $expected = null;
        if ($expectedDiffSignature !== null) {
            $entry = new DBExpectedDiffSignature();
            $entry->sqlTableName = $diff->sqlTableName;
            $entry->signature = $expectedDiffSignature;
            $expected = new DBExpectedDiffSignatures();
            $expected->add($entry);
        }
        return $this->applyDiffs($set, $disableForeignKeyChecks, $bypassProductionGuard, $expected);
    }

    /**
     * Stable error-code prefix on every signature-gate failure. Frontends should match on this
     * prefix (substring `self::ERROR_CODE_DIFF_SIGNATURE_MISMATCH`) instead of parsing the prose
     * tail of the message — the human text is allowed to change/translate, the prefix is contract.
     */
    public const string ERROR_CODE_DIFF_SIGNATURE_MISMATCH = '[DIFF_SIGNATURE_MISMATCH]';

    /**
     * Verifies the freshly-computed diff list matches what the caller intended to apply.
     *
     * The HTTP apply flow always recomputes the diff fresh in the controller — there's no way to
     * trust a `DBTableDiff` passed in by HTTP (it could be tampered with, and a stale frontend
     * may hold an old shape). The check is "the freshly-computed signature must equal the one
     * the operator captured when they viewed the diff." Any mismatch — entity edit, live drift,
     * concurrent apply — short-circuits the run with an actionable error.
     *
     * Bypass: callers that pass `$expected = null` (CLI commands, messenger handlers, anyone who
     * wants the old fire-and-forget behaviour) skip this check entirely. HTTP DTOs should always
     * pass a signature. The pattern intentionally mirrors `$bypassProductionGuard`: opt-out is
     * explicit and audit-friendly.
     *
     * Strict cover: when `$expected !== null` it MUST cover the set of diffs being applied —
     * extra-in-diffs or missing-in-expected both throw. This closes a footgun where a partial
     * map silently lets the un-covered diffs apply ungated (the exact "view ≠ execute" gap the
     * signature gate exists to close).
     *
     * @throws BadRequestException
     */
    protected function assertDiffSignaturesMatch(
        DBTableDiffs $diffs,
        ?DBExpectedDiffSignatures $expected
    ): void {
        if ($expected === null) {
            return;
        }

        $diffTableNames = [];
        foreach ($diffs->getElements() as $diff) {
            $diffTableNames[$diff->sqlTableName] = true;
        }
        $expectedTableNames = [];
        foreach ($expected->getElements() as $entry) {
            $expectedTableNames[$entry->sqlTableName] = true;
        }

        // Strict-cover: every expected entry must correspond to a diff in the set, and every diff
        // must have an expected signature. Partial coverage would reintroduce the very gap this
        // gate exists to close.
        $missingFromExpected = array_diff(array_keys($diffTableNames), array_keys($expectedTableNames));
        $extraInExpected = array_diff(array_keys($expectedTableNames), array_keys($diffTableNames));
        if ($missingFromExpected !== [] || $extraInExpected !== []) {
            $parts = [];
            if ($missingFromExpected !== []) {
                $parts[] = 'missing signatures for: ' . implode(', ', $missingFromExpected);
            }
            if ($extraInExpected !== []) {
                $parts[] = 'unknown tables in signature set: ' . implode(', ', $extraInExpected);
            }
            throw new BadRequestException(
                self::ERROR_CODE_DIFF_SIGNATURE_MISMATCH
                . ' Signature set does not cover the diff set ('
                . implode('; ', $parts)
                . '). Refresh the diff page and re-submit so every diff in the batch carries a signature.'
            );
        }

        foreach ($diffs->getElements() as $diff) {
            $tableName = $diff->sqlTableName;
            $actual = $diff->diffSignature;
            if ($actual === null) {
                // The diff was hand-built (e.g. CLI tooling) and never went through computeDiffs(),
                // so it has no signature to compare. Reject explicitly rather than silently passing
                // because the caller did opt in by sending an expectedDiffSignature — telling them
                // "current: ``" is confusing. Document the intent clearly.
                throw new BadRequestException(
                    self::ERROR_CODE_DIFF_SIGNATURE_MISMATCH
                    . " Diff for table `$tableName` was not produced by computeDiffs() and carries "
                    . 'no signature. The signature gate is only applicable to diffs computed by the '
                    . 'framework — pass null/omit expectedDiffSignature for hand-built diffs.'
                );
            }
            $expectedSignature = $expected->getSignatureByTableName($tableName);
            if ($actual !== $expectedSignature) {
                throw new BadRequestException(
                    self::ERROR_CODE_DIFF_SIGNATURE_MISMATCH
                    . " Diff for table `$tableName` changed since it was last viewed. "
                    . 'Refresh the diff page, review the new SQL, and apply again. '
                    . "Expected signature: `$expectedSignature`, current: `$actual`."
                );
            }
        }
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
        $stats = $this->getTableSizeStats($diff->sqlTableName);
        if ($stats === null || !$stats->isLarge(self::LARGE_TABLE_SIZE_THRESHOLD_MB, self::LARGE_TABLE_ROW_THRESHOLD)) {
            return;
        }
        $riskyOperations = $this->detectCopyForcingOperations($diff);
        if ($riskyOperations->count() === 0) {
            return;
        }
        throw new BadRequestException(
            $this->buildProductionGuardMessage(
                $diff->sqlTableName,
                $stats->sizeMb,
                $stats->rowCount,
                $riskyOperations
            )
        );
    }

    /**
     * True when the table exceeds either the size or row threshold. Convenience wrapper around
     * {@see self::getTableSizeStats()} + {@see DBTableSizeStats::isLarge()} for external callers
     * that just want a yes/no answer (the diff service itself reaches for the structured stats VO
     * so it can also surface the figures in error messages).
     */
    public function isLargeTable(string $sqlTableName): bool
    {
        return $this->getTableSizeStats($sqlTableName)
            ?->isLarge(self::LARGE_TABLE_SIZE_THRESHOLD_MB, self::LARGE_TABLE_ROW_THRESHOLD)
            ?? false;
    }

    /**
     * Returns the live table's size in MB and estimated row count, or null when the table is
     * missing or the query fails.
     *
     * `table_rows` from INFORMATION_SCHEMA is an *estimate* on InnoDB (statistics-derived, not a
     * COUNT(*)). For guardrail purposes the estimate is more than accurate enough — we're checking
     * orders of magnitude, not exact thresholds.
     */
    public function getTableSizeStats(string $sqlTableName): ?DBTableSizeStats
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
        $stats = new DBTableSizeStats();
        $stats->sizeMb = $row['size_mb'] !== null ? (int)$row['size_mb'] : null;
        $stats->rowCount = $row['row_count'] !== null ? (int)$row['row_count'] : null;
        return $stats;
    }

    /**
     * Enumerates which child diffs in a table-level diff are COPY-forcing under MariaDB/InnoDB.
     * Used by {@see self::assertSafeForDirectApply()} to build the error message. Returns an empty
     * set when the diff is online-safe (i.e. all operations are INSTANT- or INPLACE-eligible).
     */
    protected function detectCopyForcingOperations(DBTableDiff $diff): DBCopyForcingOperations
    {
        $risky = new DBCopyForcingOperations();
        // Column attribute changes that force or risk ALGORITHM=COPY:
        //   - sqlType: type change (INT→BIGINT, ENUM→VARCHAR, etc.) → always COPY
        //   - length: VARCHAR shrink/grow → typically COPY
        //   - vectorDimensions: VECTOR re-dim → DROP+ADD+backfill
        //   - allowsNull: nullability change on a large table can force a full row-scan ALTER under
        //     Galera TOI. Relax (NOT NULL→NULL) is usually INPLACE-fast, tighten (NULL→NOT NULL)
        //     needs to validate every row and frequently falls back to COPY. Treat both as risky
        //     on large tables — the operator should explicitly choose pt-osc with the right hints.
        $copyForcingColumnAttributes = ['sqlType', 'length', 'vectorDimensions', 'allowsNull'];

        $append = static function (DBCopyForcingOperations $set, string $description): void {
            $op = new DBCopyForcingOperation();
            $op->description = $description;
            $set->add($op);
        };
        foreach ($diff->columnDiffs->getElements() as $cd) {
            if ($cd->changeKind !== DBColumnDiff::CHANGE_KIND_MODIFY) {
                continue;
            }
            if ($cd->requiresFullReset) {
                $append($risky, "MODIFY column `$cd->columnName` requires full reset (VECTOR re-dimensioning forces DROP+ADD+backfill)");
                continue;
            }
            $copyForcingChanges = array_intersect($cd->changedAttributes, $copyForcingColumnAttributes);
            if (!empty($copyForcingChanges)) {
                $reason = in_array('allowsNull', $copyForcingChanges, true) && count($copyForcingChanges) === 1
                    ? 'nullability change can force full row-scan ALTER on large tables (use pt-osc with INPLACE/LOCK hint)'
                    : 'column-type or size change forces ALGORITHM=COPY';
                $append($risky, "MODIFY column `$cd->columnName` — changes " . implode(', ', $copyForcingChanges) . " ($reason)");
            }
        }
        foreach ($diff->virtualColumnDiffs->getElements() as $vcd) {
            if ($vcd->changeKind === DBVirtualColumnDiff::CHANGE_KIND_MODIFY) {
                $append($risky, "MODIFY virtual column `$vcd->columnName` (generation-expression change requires DROP+ADD — MySQL forbids in-place ALTER)");
            }
        }
        foreach ($diff->indexDiffs->getElements() as $id) {
            if ($id->changeKind === DBIndexDiff::CHANGE_KIND_ADD
                && $id->targetIndex !== null
                && $id->targetIndex->indexType === DatabaseIndex::TYPE_FULLTEXT) {
                $indexName = $id->targetIndex->indexName ?? '(unnamed)';
                $append($risky, "ADD FULLTEXT INDEX `$indexName` (FULLTEXT index creation forces ALGORITHM=COPY)");
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
        foreach ($diff->sqlStatements->getElements() as $statement) {
            // Defensive: each sqlStatements entry SHOULD already be a single executable statement.
            // CREATE_TABLE diffs originally come from DatabaseModel::getSql() as one multi-statement
            // blob and we pre-split them in computeTableDiff. We re-split here as belt-and-braces
            // so a future caller cannot accidentally hand us a multi-statement entry that Doctrine
            // DBAL would reject (executeStatement() goes through prepare+execute → MySQL forbids
            // multi-statement prepared queries by default).
            foreach ($this->splitMultiStatementSql($statement->sql) as $singleStatement) {
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
            $diff->sqlStatements = DBSqlStatements::fromStringList(
                $this->splitMultiStatementSql($createTableSql)
            );
            $diff->severity = DBTableDiff::SEVERITY_ADDITIVE;
            // Signature is set by computeDiffs() after the production-guard decorator runs so the
            // hash covers severity/blocked/size fields too. See self::computeDiffSignature().
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
            $collationChange = new DBCollationChange();
            $collationChange->from = $current->collation;
            $collationChange->to = $databaseModel->collation;
            $diff->collationChange = $collationChange;
        }

        if ($diff->isEmpty()) {
            $diff->changeType = DBTableDiff::CHANGE_TYPE_NO_CHANGE;
            return $diff;
        }

        $diff->changeType = DBTableDiff::CHANGE_TYPE_ALTER_TABLE;
        $diff->sqlStatements = DBSqlStatements::fromStringList(
            $this->assemblePhaseOrderedStatements($databaseModel, $diff)
        );
        $diff->sql = $diff->sqlStatements->count() > 0
            ? implode(";\n", $diff->sqlStatements->toStringList()) . ';'
            : '';
        $diff->severity = $this->classifySeverity($diff);
        // Signature is set by computeDiffs() after the production-guard decorator runs so the
        // hash covers severity/blocked/size fields too. See self::computeDiffSignature().

        return $diff;
    }

    /**
     * Stable digest of everything an operator commits to when they click Apply. Two compute runs
     * that produce identical operator-visible diffs (same SQL, same decoration the UI rendered)
     * yield the same signature; any change to either side flips it and the apply gate refuses.
     *
     * Hash inputs are intentionally broader than just the SQL list:
     *
     *  • `sqlStatements`         — what would actually run. Sorted within each phase via the
     *    canonical sort below to make the hash insensitive to entity-property reorderings or
     *    INFORMATION_SCHEMA column-order differences that don't change the semantic diff.
     *  • `sqlTableName`          — anchors the hash to the table so an empty/trivial statement
     *    set can't collide across tables (or across compute runs against different tables).
     *  • `changeType`, `severity` — the operator's risk assessment lives here. A diff that flips
     *    ADDITIVE → DESTRUCTIVE without changing SQL (e.g. a severity-rule code change) MUST
     *    re-prompt for review.
     *  • `directApplyBlocked`    — the production-guard decision the operator saw. A diff that
     *    flips from blocked → unblocked between view and apply (table grew/shrunk past the
     *    threshold) MUST re-prompt.
     *  • `tableSizeMb` — bucketed by order of magnitude. Computed from `data_length+index_length`
     *    in `INFORMATION_SCHEMA.TABLES`, which is stable between ANALYZE runs (unlike
     *    `table_rows`, which is an InnoDB estimate that fluctuates ±20–40% — including it would
     *    churn the signature for tables sitting near a bucket boundary). Size buckets give
     *    DROP_TABLE diffs a real time-anchor without false-positive jitter.
     *
     * Deliberately NOT in the payload:
     *  • `tableRowCount` — too jittery, see above.
     *  • `collationChange` — covered transitively via `sqlStatements` (collation diff emits an
     *    ALTER TABLE COLLATE statement).
     *  • `directApplyBlockReason` / `copyForcingOperations` — derived from `directApplyBlocked`
     *    + table stats, which are already in the payload.
     *
     * @see self::canonicalSortedSqlStatements()
     */
    protected function computeDiffSignature(DBTableDiff $diff): string
    {
        $payload = [
            'sqlTableName' => $diff->sqlTableName,
            'changeType' => $diff->changeType,
            'severity' => $diff->severity,
            'directApplyBlocked' => $diff->directApplyBlocked,
            'sizeBucket' => $this->bucketTableMagnitude($diff->tableSizeMb),
            'statements' => $this->canonicalSortedSqlStatements($diff->sqlStatements->toStringList()),
        ];
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        return hash('sha256', $encoded);
    }

    /**
     * Buckets a magnitude (MB or rows) into order-of-magnitude tiers so the signature isn't
     * churned by minor day-to-day drift, but does flip when the table moves a tier. Null in →
     * null out (preserves the distinction "missing stat" from "0").
     */
    protected function bucketTableMagnitude(?int $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value <= 0) return '0';
        if ($value < 100) return '<100';
        if ($value < 1_000) return '<1k';
        if ($value < 10_000) return '<10k';
        if ($value < 100_000) return '<100k';
        if ($value < 1_000_000) return '<1M';
        if ($value < 10_000_000) return '<10M';
        return '>=10M';
    }

    /**
     * Returns statements grouped by phase prefix and sorted within each group. The phase prefix
     * comes from the SQL verb pattern (DROP TRIGGER < DROP FK < DROP INDEX < DROP COLUMN <
     * ALTER COLLATE < MODIFY COLUMN < ADD COLUMN < ADD INDEX < ADD FK < UPDATE < CREATE TRIGGER)
     * which is the same order the assembler already emits — we re-sort within each phase only,
     * never across phases, because DDL ordering is meaningful between phases. Within a phase,
     * order is irrelevant for correctness, so a canonical lexicographic sort makes the signature
     * stable across reflection/INFORMATION_SCHEMA insertion-order variations.
     *
     * @param string[] $statements
     * @return string[]
     */
    protected function canonicalSortedSqlStatements(array $statements): array
    {
        if (empty($statements)) {
            return [];
        }
        $phased = [];
        foreach ($statements as $stmt) {
            // Whitespace normalisation: collapse runs of whitespace to a single space and trim.
            // Signature compares snapshot-vs-fresh statements that travel through different render
            // helpers, so any future formatting tweak (re-indented column lists, extra newlines
            // between IF NOT EXISTS clauses, etc.) would otherwise invalidate every in-flight
            // signature even though the SQL is semantically identical. The phase prefix and the
            // sort key both operate on the normalised form so within-phase ordering also stays
            // stable across renderer changes.
            $normalised = trim((string)preg_replace('/\s+/', ' ', $stmt));
            $phase = $this->statementPhasePrefix($normalised);
            $phased[$phase][] = $normalised;
        }
        $sorted = [];
        foreach ($phased as $group) {
            sort($group);
            foreach ($group as $stmt) {
                $sorted[] = $stmt;
            }
        }
        return $sorted;
    }

    /**
     * Cheap heuristic — group statements by their first verbs so canonicalSortedSqlStatements()
     * can sort within each phase without resorting across. Granularity matches the assembler's
     * phase order; unknown statement shapes fall into a "z_other" bucket sorted last.
     */
    protected function statementPhasePrefix(string $statement): string
    {
        $upper = strtoupper(ltrim($statement));
        return match (true) {
            str_starts_with($upper, 'DROP TRIGGER')                                        => '00_drop_trigger',
            str_contains($upper, 'DROP FOREIGN KEY')                                       => '01_drop_fk',
            str_contains($upper, 'DROP INDEX')                                             => '02_drop_index',
            str_contains($upper, 'DROP COLUMN')                                            => '04_drop_column',
            str_contains($upper, 'COLLATE')                                                => '05_collate',
            str_contains($upper, 'MODIFY COLUMN')                                          => '06_modify_column',
            str_contains($upper, 'ADD COLUMN')                                             => '07_add_column',
            str_starts_with($upper, 'CREATE INDEX') || str_starts_with($upper, 'CREATE UNIQUE INDEX')
                || str_starts_with($upper, 'CREATE FULLTEXT INDEX') || str_starts_with($upper, 'CREATE SPATIAL INDEX')
                || str_starts_with($upper, 'CREATE VECTOR INDEX')                          => '09_add_index',
            str_contains($upper, 'ADD CONSTRAINT')                                         => '10_add_fk',
            str_starts_with($upper, 'UPDATE ')                                             => '11_data_backfill',
            str_starts_with($upper, 'CREATE TRIGGER') || str_starts_with($upper, 'CREATE OR REPLACE TRIGGER')
                || (str_contains($upper, 'CREATE') && str_contains($upper, 'TRIGGER'))     => '12_create_trigger',
            str_starts_with($upper, 'CREATE TABLE')                                        => '03_create_table',
            str_starts_with($upper, 'DROP TABLE')                                          => '04_drop_table',
            default                                                                        => 'z_other',
        };
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
        $diff->sqlStatements = DBSqlStatements::fromStringList([$statement]);
        // Signature is set by computeDiffs() after the production-guard decorator runs so live
        // tableSizeMb / tableRowCount get mixed in — without that, every DROP_TABLE for the same
        // table name produces the same signature forever (the SQL is `DROP TABLE \`x\``, period),
        // letting an operator apply a week-old DROP against a table that grew 50M rows since.
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
        // Carry the per-column collation override declared on the DatabaseColumn attribute,
        // if any. Silent (null) entities leave the comparator to skip collation comparison so
        // we don't churn diffs against the live table's default collation.
        $column->collation = isset($col->collation) ? $col->collation : null;
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
        // Collation drift detection. Only fires when the entity explicitly declared an override
        // via #[DatabaseColumn(collation: '...')]. If the target side is null (entity is silent)
        // we don't compare — otherwise every character column on every entity would churn diffs
        // against whichever utf8mb4_* collation the live table happens to use as default. With an
        // explicit override, mismatch → MODIFY emitted, and the column SQL re-emits with the
        // requested CHARACTER SET / COLLATE clause.
        if ($target->collation !== null
            && $current->collation !== null
            && strcasecmp($target->collation, $current->collation) !== 0) {
            $changed[] = 'collation';
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
