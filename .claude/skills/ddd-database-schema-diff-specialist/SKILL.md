---
name: ddd-database-schema-diff-specialist
description: Understand and operate the DDD live-vs-target database schema diff system, and stand up an admin interface for it in a consuming application. Use when computing diffs between entity-derived schema and the live database, applying drift fixes, debugging false positives, or wiring the diff endpoints + admin screen into a new project.
metadata:
  author: mgamadeus
  version: "1.0.0"
---

# DDD Database Schema Diff Specialist

The diff system reads what your `DDD\` entities say the database *should* look like, introspects what it *actually* looks like via `INFORMATION_SCHEMA`, returns a structured diff, and lets you apply per-table or in bulk through admin endpoints. Replaces the legacy "emit a multi-statement SQL blob and let the operator paste it into a client" workflow.

## When to Use

- Implementing or extending the admin Schema Diff screen in a consuming app.
- Debugging false-positive MODIFY diffs (most common: MariaDB `"NULL"` defaults, generation-expression formatting, virtual-column sqlType).
- Adding new aspects to the diff (currently: columns, virtual columns, indexes, FKs, triggers, collation).
- Operating VECTOR column dimensionality changes (require DROP + ADD + zero-fill backfill).
- Migrating Translatable columns from `LONGTEXT` to `JSON` or similar in-place type changes.

## Where the Code Lives (DDD Core)

All diff machinery is in `mgamadeus/ddd`. Consuming apps build only thin endpoint + DTO wrappers.

### Value objects — `src/Domain/Base/Repo/DB/Database/Canonical/`

Both sides of the diff (live introspection and target generator) converge on these typed snapshots. The typed constructors catch field-drift bugs at parse time.

| File | Purpose |
|---|---|
| `DBCanonicalColumn.php` | One column (real OR virtual — `isGenerated` discriminates). |
| `DBCanonicalIndex.php` | One index. Matched by `(indexType, indexColumns)` — name irrelevant. |
| `DBCanonicalForeignKey.php` | One FK. Matched by `(internalIdColumn, foreignTable, foreignIdColumn)`. |
| `DBCanonicalTrigger.php` | One trigger. Carries `actionStatement` (live) + `rawSql` (target). |
| `DBCanonicalTable.php` | Aggregate of columns / virtualColumns / indexes / foreignKeys / triggers + collation. |

### Diff value objects — `src/Domain/Base/Repo/DB/Database/Diff/`

| File | Purpose |
|---|---|
| `DBColumnDiff(s).php` | Column ADD/DROP/MODIFY. Has `requiresFullReset` + `resetSql` for VECTOR dimensionality changes. |
| `DBVirtualColumnDiff(s).php` | Virtual column ADD/DROP/MODIFY (MODIFY is always DROP+ADD — MySQL forbids ALTER on generation expressions). |
| `DBIndexDiff(s).php` | Index ADD/DROP. No MODIFY: matched by definition, any difference *is* the key. |
| `DBForeignKeyDiff(s).php` | FK ADD/DROP/MODIFY (MODIFY = DROP+ADD). |
| `DBTriggerDiff(s).php` | Trigger ADD/DROP/MODIFY (MODIFY = DROP+CREATE). |
| `DBTableDiff(s).php` | Per-table aggregate. Carries `changeType`, `severity`, child diff sets, phase-ordered `sqlStatements`. |

### Services — `src/Domain/Common/Services/`

| File | Purpose |
|---|---|
| `DatabaseSchemaIntrospectionService.php` | The only thing that talks to `INFORMATION_SCHEMA`. Per-request cache. Returns `?DBCanonicalTable`. |
| `DatabaseSchemaDiffService.php` | Orchestrator. `computeDiffs()`, `applyDiff()`, `applyDiffs()`. ~1000 LOC including the phase-ordered SQL assembler. |

### Critical: hide rich DDD types from the wire

`target*` fields on the diff VOs (e.g. `DBColumnDiff::$targetColumn`) carry rich DDD types (`DatabaseColumn`, `DatabaseIndex`, …) used internally for SQL rendering. They must be excluded from BOTH the JSON output AND the OpenAPI schema:

```php
#[HideProperty]   // serializer trait — strips from JSON
#[Ignore]         // OpenAPI autodocumenter — stops type-graph walking
public ?DatabaseColumn $targetColumn = null;
```

Both attributes are required. `#[HideProperty]` alone leaves the type in the schema; `#[Ignore]` alone leaves it in JSON. The autodocumenter cannot serialise these types' attribute graphs (dynamic `#[Choice(callback:)]`, lazy-load directives, encryption scopes) and will return HTTP 500 on `/api/admin/documentation/openApi` if exposed.

## How Compute Works

`DatabaseSchemaDiffService::computeDiffs(?array $entityClasses = null): DBTableDiffs`

1. Build target: `EntityModelGeneratorService::getDatabaseModels($entityClasses)`. That generator already filters out framework-side entities whose short class name is reused by an app-side descendant (via `filterOutOverriddenEntities()` walking parents and stopping at `#[SubclassIndicator]`). Without this, both classes would emit a `DatabaseModel` with the same `sqlTableName` and the diff would double up.
2. Get live tables. Skip ignored ones (`DB_DIFF_IGNORED_TABLES` env, default `['doctrine_migration_versions', 'messenger_messages']`).
3. For each target `DatabaseModel`:
   - Skip STI subclasses (`$databaseModel->parentEntityCLassWithNamespace !== null`) — their tables are owned by the STI parent.
   - Introspect the live table.
   - Absent → `CREATE_TABLE` diff with `sql = $databaseModel->getSql()`, pre-split into `sqlStatements`.
   - Present → compute per-aspect diffs, assemble phase-ordered statements, classify severity.
4. Live tables not in target and not ignored → `DROP_TABLE` diff (always `DESTRUCTIVE`).
5. Filter out `NO_CHANGE` rows before returning.

## Phase-Ordered SQL Assembly

The big risk in naive ordering: `DROP COLUMN` fails if an index still references it; `ADD FK` fails before the referenced column exists. The assembler emits in 13 phases:

| Phase | Operation |
|---|---|
| **0** | DROP triggers (drop-half of MODIFY too) — first so DDL isn't perturbed by BEFORE triggers on columns about to drop. |
| 1 | DROP foreign keys |
| 2 | DROP indexes |
| 3 | DROP virtual columns |
| 4 | DROP real columns (+ drop-half of VECTOR-reset MODIFY) |
| 5 | Collation change |
| 6 | MODIFY real columns (in-place; excludes vector-reset) |
| 7 | ADD real columns (+ add-half of VECTOR-reset MODIFY) |
| 8 | ADD virtual columns |
| 9 | ADD indexes |
| 10 | ADD foreign keys |
| **11** | Data backfill (VECTOR zero-fill UPDATEs) — runs without triggers, after columns are in final shape. |
| **12** | CREATE triggers — last so trigger bodies reference final-shape columns AND backfill UPDATEs don't fire them. |

`DBTableDiff::sqlStatements` is the phase-ordered execution list. The per-aspect `sql` field on child diffs is **for frontend display only** — it's the conceptual operation (e.g. drop+add concatenated for a MODIFY), not the order of execution.

### SQL splitter

`splitMultiStatementSql($sql): string[]` strips banner comments (`#`-prefixed), splits on `;`, trims, filters empties. Required because Doctrine DBAL's `executeStatement()` goes through prepare+execute and MySQL forbids multi-statement prepared queries — sending a multi-statement string crashes. `executeTableDiff` re-runs every entry through the splitter as belt-and-braces.

## Critical Normalisations (False-Positive Avoidance)

These live in `DatabaseSchemaIntrospectionService`. Without them you get hundreds of false positives. Each one is justified by a real production signal — add new ones iteratively as cases surface.

### A. `BOOLEAN` ↔ `TINYINT(1)`
MySQL stores BOOLEAN as TINYINT(1) under the hood. `DATA_TYPE` returns `tinyint`. Detect `DATA_TYPE === 'TINYINT' && length === 1` and rewrite `sqlType` to `BOOLEAN`.

### B. MariaDB `"NULL"` string default (biggest false-positive source)
MariaDB returns the **literal string `"NULL"`** in `COLUMN_DEFAULT` for `DEFAULT NULL` columns. MySQL 8+ returns actual PHP `null`. DDD writes `DEFAULT NULL` for every nullable column. Without normalising, every nullable column on MariaDB looks like a MODIFY forever:

```php
if ($defaultValue !== null) {
    $defaultValue = (string)$defaultValue;
    if (strtoupper($defaultValue) === 'NULL') {
        $defaultValue = null;
    }
}
```

### C. Integer display width is irrelevant
MySQL stores `INT(11)`, but the width is a display hint with no semantic meaning. Only keep `length` for `VARCHAR`/`CHAR` (and `vectorDimensions` for `VECTOR`). Everything else → `length = null` regardless of `CHARACTER_MAXIMUM_LENGTH`.

### D. Generation expression canonicalisation
MySQL stores `(IFNULL(tableNumber, 0))` as `` ifnull(`tableNumber`,0) `` post-parse. DDD writes the original source. Both go through `normaliseGenerationExpression`: lowercase, strip backticks, strip outer parens (only when they wrap the whole expression), collapse whitespace, remove spaces around `(`, `)`, `,`. Extend iteratively as edge cases surface (`JSON_UNQUOTE`, multi-arg functions, etc.).

### E. Expression defaults (`CURRENT_TIMESTAMP`, `UUID()`, …)
When `EXTRA` contains `DEFAULT_GENERATED` and `isGenerated` is false, the default is an SQL expression, not a literal. DDD cannot express these on the target side. Naive comparison would always emit a MODIFY that strips the default in production. Fix: set `defaultIsExpression = true` on the canonical column, then in `compareCanonicalColumns()` skip the `defaultValue` check when the live side has it. **Live wins.**

### F. Trigger body normalisation
Live side gives `action_statement` (body only); target side gives the full `CREATE TRIGGER` statement from a `.sql` file. Both normalised via `normaliseTriggerBody`: lowercase, strip backticks, collapse whitespace, strip outer `BEGIN…END`, trim trailing semicolons.

### G. Virtual column `sqlType` comparison disabled
The DDD-emitted virtual column references the underlying column's `getSqlType()` which adds a length suffix (`VARCHAR(255)`); introspection produces bare `VARCHAR` + `length` separately. String comparison would always mismatch. The substantive change is captured by `generationExpression` + `isStored` — those alone drive the virtual-column MODIFY signal.

## VECTOR Column Reset Semantics

MariaDB cannot ALTER a VECTOR column's dimensionality in place — storage is dimension-tagged binary. When a diff detects `sqlType` or `vectorDimensions` change on a VECTOR column:

1. `DBColumnDiff::$requiresFullReset = true`.
2. `resetSql = "UPDATE \`t\` SET \`c\` = VEC_FromText('[0,0,…,0]')"` with N target dimensions.
3. Phase assembler routes through Phase 4 (DROP) → Phase 7 (ADD) → Phase 11 (zero-fill backfill).
4. Severity always `DESTRUCTIVE` (data replaced).

Also applied to **fresh ADD** of VECTOR columns so search code never encounters NULL embeddings on existing rows.

**Limitation:** NOT NULL VECTOR ADDs on populated tables aren't fully handled — the ADD COLUMN fails before zero-fill can run. Typical DDD vector columns are nullable; if you need NOT NULL the path would be ADD-nullable → backfill → MODIFY to enforce NOT NULL (currently not implemented).

## Severity Classification

`classifySeverity(DBTableDiff): string`:

| Severity | Trigger |
|---|---|
| `ADDITIVE` | All children are ADDs; no destructive flags. |
| `DESTRUCTIVE` | Any of: DROP column/index/FK/trigger/virtual-column/table, NOT NULL added to previously nullable column, VARCHAR shrunk, sqlType change, VECTOR reset. |
| `MIXED` | Has both. |

`columnModifyIsDestructive()` is the single source of truth for column-level destructive checks.

## Reason Before You Apply — Which Side Is Wrong?

A diff is **drift**, not a verdict. The tool tells you "entity-derived schema and live schema disagree." It does **not** tell you which side represents the truth. Before clicking Apply, ask:

> Is the DB the right shape, or is the entity?

Three possible resolutions for any diff row:

| Resolution | When | Action |
|---|---|---|
| **Apply DB change** | Entity is correct; DB drifted away from intent. | Click Apply — the SQL conforms the DB to the entity. |
| **Fix the entity** | DB is correct; entity declaration is wrong / missing an attribute. | Edit the PHP entity (add `#[NotNull]`, `#[DatabaseIndex(TYPE_NONE)]`, default value, etc.), regenerate the Doctrine model, refresh the diff. The diff disappears without any DB DDL. |
| **Fix both** | Neither side matches the workflow's truth. | Edit entity to the desired shape, then apply the resulting (now smaller) diff. |

### The asymmetry you must respect

- **Relaxing** (drop NOT NULL, drop index, widen VARCHAR) is one-way safe — old rows still fit, new rows have more freedom.
- **Tightening** (add NOT NULL on a previously nullable column, add UNIQUE on a column with duplicates, narrow VARCHAR below max length, add a FK constraint on data that violates it) **can fail at apply-time or break the application at runtime** even when the DDL succeeds.

A tightening diff that compiles cleanly through the Production Guard is *not* a green light. The guard checks blast-radius of the operation; it cannot check whether the application semantically depends on the looser shape.

### Pre-Apply Checklist (Triple-Reasoning for Tightening Diffs)

For every diff row that adds NOT NULL, adds a UNIQUE index, narrows a column, or installs a CHECK / FK constraint:

1. **Grep the column at runtime.** `grep -rn '\$entity->propertyName\|->propertyName =' src/` — does anywhere set it to `null`, leave it uninitialised, or rely on a deferred fill (AI job, async backfill, lazy-load)?
2. **Audit the write paths.** Every controller, service method, and message handler that creates rows for this table. Does each one provide a value?
3. **Sanity-check the data.** `SELECT COUNT(*) FROM table WHERE col IS NULL` — if existing rows already violate the proposed constraint, the apply will fail OR (worse) succeed with truncation/coercion. Same for UNIQUE: `SELECT col, COUNT(*) FROM table GROUP BY col HAVING COUNT(*) > 1`.

If any of (1), (2), or (3) surfaces evidence that the looser shape is required, **do not apply** — fix the entity instead.

### Real Examples From the Radbonus Audit (2026-05-13)

**Roles.description — fix the entity, not the DB.**
- Diff: live DB has no index on `description`; entity-derived schema expected a default B-Tree index.
- Reality: `description` is freetext, never queried by equality. The DB shape (no index) is correct. The entity drifted because the framework auto-generates an index for every column unless told otherwise.
- Fix: add `#[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]` on the entity property. Diff vanishes without touching the DB.

**AccountRoles.accountId, AccountRoles.roleId — apply DB change.**
- Diff: live DB has `NOT NULL` on both; entity declared them as `?int` without `#[NotNull]`.
- Reality: a junction row with no `accountId` or no `roleId` is meaningless — every legitimate write path sets both. DB is correct; entity drifted because the developer typed them as nullable out of PHP habit.
- Fix: add `#[NotNull]` on both properties (entity) and apply the DB diff (which is empty after the entity fix on this side — the diff was DB-already-correct, entity-permissive). Result: entity + DB now both express the actual invariant.

**Roles.isAdminRole — fix the entity (TYPE_NONE + NotNull together).**
- Diff: live DB had no index and was `NOT NULL`; entity wanted an index and allowed null.
- Reality: it's a boolean flag, never queried, always set by `setName()`. The DB shape is right on both axes.
- Fix: `#[NotNull]` + `#[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]` on the entity. Both diffs disappear.

**SupportTicket.title — counter-example: tightening looked right, broke prod.**
- Diff: live DB had `NOT NULL`; entity allowed null. "Obvious" fix was to tighten the entity to match.
- Reality: the entity was correct. App-channel ticket creation (`ClientSupportController::createTicket`) sends no title — the AI pipeline populates `generatedTitle`, never `title`. `title` is only set from Gmail subjects (`SupportTicketsService:780`). The DB had drifted into being stricter than the workflow allows, and the entity was rightly permissive.
- The mistake: tightened the entity without grepping `->title =` and reading the controller. Symfony NotNull validation then rejected every app-channel POST with "This value should not be null."
- Fix: revert entity to `?string`, relax DB column back to `NULL`. **Both** sides needed to match the looser shape that the workflow actually produces.

### Heuristics

- **Defaults change the answer.** A column with a sensible default (`= self::STATUS_ACTIVE`, `= 4`, `= false`) is almost always safely NotNull — the application can't produce null. A column populated *later* (by AI, by a scheduled job, by a follow-up message) cannot be NotNull at insert time.
- **Junction tables almost always want NotNull on both FKs.** A row with one side missing is meaningless and should never have been written.
- **Freetext columns rarely want indexes.** Use `#[DatabaseIndex(TYPE_NONE)]` to silence the auto-index. A multi-column composite or a fulltext index is usually a better choice than a default B-Tree on a long varchar.
- **`generatedX` / `localizedX` / `processedX` flags** are populated by async pipelines. If `xProcessed` defaults to `false` and an async job sets it, it can be NotNull. If `generatedTitle` is filled by an LLM after row creation, it cannot.

### Workflow

1. Open the diff in the admin UI.
2. For every tightening row: run the Triple-Reasoning checklist above.
3. Decide per row: Apply | Fix Entity | Fix Both.
4. If "Fix Entity", edit the PHP, run `bin/console app:generate-doctrine-models-for-entities`, refresh the diff page — the row should disappear.
5. Apply the remaining rows that survived reasoning.
6. After apply, smoke-test the write paths that touch the changed columns (especially the public/client API). A clean Apply is not a successful change — runtime validation might still reject inputs that were previously accepted.

## Apply Path

```php
DatabaseSchemaDiffService::applyDiff(DBTableDiff $diff, bool $disableForeignKeyChecks = true, bool $bypassProductionGuard = false): DBTableDiffs
DatabaseSchemaDiffService::applyDiffs(DBTableDiffs $diffs, bool $disableForeignKeyChecks = true, bool $bypassProductionGuard = false): DBTableDiffs
```

Both return a **freshly recomputed** `DBTableDiffs` after applying so the frontend can replace its state with the response without a follow-up GET.

Inside `executeTableDiff`:
- Wraps with `SET FOREIGN_KEY_CHECKS=0/1` when `$disableForeignKeyChecks`.
- Re-splits every statement through `splitMultiStatementSql` defensively.
- Each statement goes through `executeStatement` independently. DDL is implicit-commit on MySQL — failure mid-list leaves a mixed state. Re-introspection on return surfaces what's still pending.

### Production Guard (large-table refusal)

`applyDiffs()` calls `assertSafeForDirectApply()` for every diff before executing. The guard throws `BadRequestException` when:

1. The live table exceeds **either** `LARGE_TABLE_SIZE_THRESHOLD_MB` (default 100 MB, data + index from `INFORMATION_SCHEMA.TABLES`) **or** `LARGE_TABLE_ROW_THRESHOLD` (default 100,000 rows, InnoDB estimate from `INFORMATION_SCHEMA.TABLES.TABLE_ROWS`), **AND**
2. The diff contains at least one COPY-forcing operation, detected by `detectCopyForcingOperations(DBTableDiff): string[]`:
   - Column MODIFY with `sqlType` / `length` / `vectorDimensions` in `changedAttributes`
   - Column MODIFY with `requiresFullReset = true` (VECTOR re-dimensioning)
   - Virtual column MODIFY (always DROP+ADD — MySQL forbids in-place ALTER on generation expressions)
   - Index ADD with `indexType === DatabaseIndex::TYPE_FULLTEXT`

Bypass is **only** via the `$bypassProductionGuard = true` parameter on `applyDiff()`/`applyDiffs()`. By design the parameter is **not** exposed through any HTTP DTO — the admin UI cannot bypass. CLI commands and Symfony Messenger handlers (e.g. an Agent running pt-osc orchestration) can pass `true`.

### Production-Guard Signal on `DBTableDiff`

`computeDiffs()` decorates every `DBTableDiff` with five additional fields so the frontend can render the block proactively (disabled Apply button + warning banner) rather than only learning about it on click:

| Field | Type | Purpose |
|---|---|---|
| `tableSizeMb` | `?int` | Live table size MB (data + index). `null` for `CREATE_TABLE`. |
| `tableRowCount` | `?int` | InnoDB-estimated row count. `null` for `CREATE_TABLE`. |
| `directApplyBlocked` | `bool` | When `true`, the UI must disable Apply and surface the reason. |
| `directApplyBlockReason` | `?string` | Pre-formatted message (multi-paragraph) with stats, risky operations, and the pt-osc redirect. |
| `copyForcingOperations` | `string[]` | Structured list of risky operations (one item per blocking operation), suitable for bullet-list rendering. |

The decorator and the throw share the same `buildProductionGuardMessage()` helper — both paths surface identical wording.

Threshold rationale: matches the `rb-db-online-schema-update-specialist` skill's decision tree. Tables below both thresholds absorb a brief TOI block from direct ALTER; above either threshold, COPY-forcing operations must go through pt-online-schema-change.

### UX requirements for blocked diffs

The block must be **proactively visible** before the operator clicks anything — not surfaced only as an error on submit. Three principles:

**1. Never render a disabled-looking Apply button as the "blocked" affordance.** A grey or yellow "Blocked" button that still looks like a button is the worst-of-both-worlds UX: it screams "click me" while doing nothing. Either:

- **Remove** the Apply button entirely when blocked (preferred). A subsequent badge + warning banner do the explaining.
- **Hide** it via `{!blocked && <button …>Apply</button>}`. CSS-disabled buttons are not enough — the cognitive cost of "why is this orange-and-disabled" is higher than the cost of one missing button.

```tsx
// GOOD — button is absent when blocked, banner explains
{!blocked && (
  <button onClick={handleApply} disabled={isApplying} className={button.button.primary}>
    {isApplying ? "Applying…" : "Apply"}
  </button>
)}

// BAD — button still rendered but disabled, looks broken
<button
  disabled={blocked}
  className={button.button.primary}
>
  {blocked ? "Blocked" : "Apply"}
</button>
```

**2. Scan-block the card at three levels of detail** so the operator notices the block from any zoom level:

- **Card border** — recolour to subtle red (`border-red-300`) when `directApplyBlocked === true`. Visible while skimming the list.
- **Header badge** — add an `"Apply blocked"` chip next to the existing changeType / severity / size chips. Inline with where the operator already looks for status.
- **Inline banner** — full multi-line warning below the card header, in red-tinted card style: bold heading, prose explanation of why, bullet-list of `copyForcingOperations`, and a single line on how to proceed (`rb-db-online-schema-update-specialist` skill).

**3. Always render the size/rows stats** regardless of block status — both as a compact badge in the header row (`"374 MB · 290,067 rows"`). The operator should be able to look at any diff card and instantly know whether they're dealing with a small table or a giant one.

```tsx
{(tableSizeMb != null || tableRowCount != null) && (
  <span className="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-700">
    {tableSizeMb != null && `${tableSizeMb} MB`}
    {tableSizeMb != null && tableRowCount != null && " · "}
    {tableRowCount != null && `${formatNumber(tableRowCount)} rows`}
  </span>
)}
```

**4. The block-reason banner must state THIS TABLE's specific stats vs the thresholds.** Vague "table is too large" is not enough — the operator needs to see the actual numbers to internalise why this specific table was blocked and why a similar diff on another table is fine. Format:

> This table is too large for direct ALTER under Galera TOI: **374 MB / 290,067 rows** (thresholds: **100 MB / 100,000 rows**). The diff contains operations that force `ALGORITHM=COPY`…

The thresholds are project-wide constants (`LARGE_TABLE_SIZE_THRESHOLD_MB`, `LARGE_TABLE_ROW_THRESHOLD`); mirror them on the frontend as plain constants so the displayed thresholds stay in sync. If the framework ever changes them, both sides update at once.

```tsx
// Mirrors DatabaseSchemaDiffService constants. Used to show thresholds in the banner.
const LARGE_TABLE_SIZE_THRESHOLD_MB = 100
const LARGE_TABLE_ROW_THRESHOLD = 100_000

// In the banner:
<p>
  This table is too large for direct ALTER:
  <strong>{tableSizeMb} MB / {formatNumber(tableRowCount)} rows</strong>
  (thresholds: <strong>{LARGE_TABLE_SIZE_THRESHOLD_MB} MB / {formatNumber(LARGE_TABLE_ROW_THRESHOLD)} rows</strong>).
</p>
```

**Anti-patterns to avoid:**

- ❌ "Apply" button that opens a modal explaining it's blocked. The operator has already clicked; the click should never have been offered.
- ❌ Tooltip-only explanations (`title="…"`). Tooltips are invisible on touch devices and to keyboard users.
- ❌ Generic "Cannot apply" toast on submit. Vague, requires the operator to retry to see it, and doesn't tell them what to do instead.
- ❌ Showing the `directApplyBlockReason` raw in a `<pre>`. The string is formatted as plain prose for log/error output; in the UI, parse out the bullet list (`copyForcingOperations`) and render structurally.
- ❌ Mentioning `bypassProductionGuard` in the UI text. That parameter is deliberately not exposed via HTTP — telling the user about it just invites them to ask for the override.

## DBTableDiff Wire Shape

| Field | Type | Purpose |
|---|---|---|
| `sqlTableName` | `string` | Table name (also `uniqueKey()`). |
| `entityClassWithNamespace` | `?ClassWithNamespace` | Source entity. `null` for DROP_TABLE. |
| `changeType` | `string` const | `CREATE_TABLE` / `DROP_TABLE` / `ALTER_TABLE` / `NO_CHANGE`. |
| `severity` | `string` const | `ADDITIVE` / `DESTRUCTIVE` / `MIXED`. |
| `columnDiffs` | `DBColumnDiffs` | |
| `virtualColumnDiffs` | `DBVirtualColumnDiffs` | |
| `indexDiffs` | `DBIndexDiffs` | |
| `foreignKeyDiffs` | `DBForeignKeyDiffs` | |
| `triggerDiffs` | `DBTriggerDiffs` | |
| `collationChange` | `?array{from,to}` | Table-level collation delta. |
| `sql` | `string` | Concat of `sqlStatements` joined by `;\n` — for frontend display only. |
| `sqlStatements` | `string[]` | Phase-ordered list of individually-executable statements. |

`DBColumnDiff` carries `columnName`, `changeKind` (`ADD`/`DROP`/`MODIFY`), `targetColumn` (hidden), `currentDefinition` (`?DBCanonicalColumn`), `changedAttributes` (`string[]` for MODIFY), `requiresFullReset`, `resetSql`, `sql`. Same structural pattern for the other per-aspect diff types.

---

## Building the Admin Interface in a Consuming App

This is the boilerplate that ships in every project's `App\` namespace. Same code regardless of project — only namespaces change.

### Step 0. Pre-flight

- Confirm the framework version contains `src/Domain/Base/Repo/DB/Database/Diff/DBTableDiff.php`. Available from `mgamadeus/ddd` v2.10.37+. If not present, escalate — the feature doesn't exist in your framework version.
- Confirm the project follows the `App\Presentation\Api\Admin\…` controller convention and uses the DDD endpoint specialist's `RequestDto` / `RestResponseDto` pattern.

### Step 1. Backend — controller

Create or extend `App\Presentation\Api\Admin\Common\Controller\DatabaseModelsController` with three methods. Class-level attributes stay as in any other admin controller: `#[Route('/common/databaseModels')]`, `#[Tag(group: 'Common', name: 'Database Models ', …)]`, `#[LogRequest(…)]`, behind `ROLE_ADMIN`.

```php
#[Get('/diff')]
#[Summary('DatabaseModels Diff')]
public function diff(
    DBTableDiffsGetRequestDto $requestDto,
    DatabaseSchemaDiffService $databaseSchemaDiffService
): DBTableDiffsGetResponseDto {
    $responseDto = new DBTableDiffsGetResponseDto();
    $responseDto->diffs = $databaseSchemaDiffService->computeDiffs();
    return $responseDto;
}

#[Post('/applyDiffs')]
#[Summary('DatabaseModels Apply All Diffs')]
public function applyDiffs(
    ApplyDiffsRequestDto $requestDto,
    DatabaseSchemaDiffService $databaseSchemaDiffService
): DBTableDiffsGetResponseDto {
    $diffs = $databaseSchemaDiffService->computeDiffs();
    if ($requestDto->sqlTableNames !== null) {
        $filtered = new DBTableDiffs();
        foreach ($requestDto->sqlTableNames as $tableName) {
            $diff = $diffs->getDiffByTableName($tableName);
            if ($diff !== null) {
                $filtered->add($diff);
            }
        }
        $diffs = $filtered;
    }
    $refreshed = $databaseSchemaDiffService->applyDiffs($diffs, $requestDto->disableForeignKeyChecks);
    $responseDto = new DBTableDiffsGetResponseDto();
    $responseDto->diffs = $refreshed;
    return $responseDto;
}

#[Post('/applyDiff')]
#[Summary('DatabaseModels Apply Single Diff')]
public function applyDiff(
    ApplyDiffRequestDto $requestDto,
    DatabaseSchemaDiffService $databaseSchemaDiffService
): DBTableDiffsGetResponseDto {
    $diffs = $databaseSchemaDiffService->computeDiffs();
    $diff = $diffs->getDiffByTableName($requestDto->sqlTableName);
    if ($diff === null || $diff->changeType === DBTableDiff::CHANGE_TYPE_NO_CHANGE) {
        throw new NotFoundException("No pending diff for table `$requestDto->sqlTableName`");
    }
    $refreshed = $databaseSchemaDiffService->applyDiff($diff, $requestDto->disableForeignKeyChecks);
    $responseDto = new DBTableDiffsGetResponseDto();
    $responseDto->diffs = $refreshed;
    return $responseDto;
}
```

### Step 2. Backend — four DTOs

In `App\Presentation\Api\Admin\Common\Dtos\DatabaseModels\`:

| File | Body |
|---|---|
| `DBTableDiffsGetRequestDto.php` | Empty `RequestDto`. No query params. |
| `DBTableDiffsGetResponseDto.php` | Extends `RestResponseDto`. `public DBTableDiffs $diffs;` with `#[Parameter(in: Parameter::RESPONSE, required: true)]`. |
| `ApplyDiffsRequestDto.php` | `public ?array $sqlTableNames = null;` + `public bool $disableForeignKeyChecks = true;` — both `#[Parameter(in: Parameter::BODY, required: false)]`. |
| `ApplyDiffRequestDto.php` | `public string $sqlTableName;` (required) + `public bool $disableForeignKeyChecks = true;`. |

### Step 3. Backend — wiring

- **Routes** — auto-discovered from `#[Route]`/`#[Get]`/`#[Post]`. No `routes.yaml` edits.
- **DI** — `DatabaseSchemaDiffService` and `DatabaseSchemaIntrospectionService` are auto-discovered by the existing `services.yaml` glob covering `DDD\Domain\Common\Services\` mapped to `vendor/mgamadeus/ddd/src/Domain/Common/Services/*`.
- **Auth** — admin routes are already behind `ROLE_ADMIN` via the existing AuthGuard.

### Step 4. Backend — `php -l` every new file. **Block** if any fail.

### Step 5. Backend — verify OpenAPI reachable

```bash
curl -sf "https://<env>/api/admin/documentation/openApi" \
  | jq '.paths | keys[] | select(test("databaseModels/(diff|applyDiff)"))'
```

Expect three lines: `/api/admin/common/databaseModels/{diff,applyDiff,applyDiffs}`. If HTTP 500: the autodocumenter is choking, typically because a rich DDD-type field is missing `#[Ignore]`. Cross-check `targetColumn` / `targetIndex` / `targetForeignKey` / `targetTrigger` / `targetVirtualColumn` fields on the diff VOs in the framework. **Block** until reachable.

### Step 6. Frontend — SDK regen

**Never write frontend code against this feature without first regenerating the SDK.** Specific reasons:
- Response DTO carries deeply nested generics (`DBTableDiffs → DBTableDiff[] → 5 child diff sets → canonical VOs`). Hand-typing is error-prone and rots fast.
- The mutation body key is the long fully-qualified DTO name (e.g. `appPresentationApiAdminCommonDtosDatabaseModelsApplyDiffRequestDto`). Not guessable.
- RTK Query tag wiring depends on generated `*Enhanced.ts` — missing the regen means apply mutations don't refresh the list.

Project-specific regen command — typically:

```bash
cd apps/web && echo "" | npm run gen:SDK
```

Verify hooks appeared:

```bash
grep -nE "useGetApiAdminCommonDatabaseModelsDiff|usePostApiAdminCommonDatabaseModelsApplyDiff" \
  apps/web/src/api/adminApi.ts
```

Should show three hook exports. **Block** if missing.

### Step 7. Frontend — sidebar wiring

Add a maintenance sidebar entry next to "Database Tables" / "Config Imports":

```tsx
import { GitCompareArrows } from 'lucide-react';

{ key: 'schema-diff', label: t('Schema Diff'), icon: GitCompareArrows,
  href: '/admin/maintenance/schema-diff' }
```

### Step 8. Frontend — page route

```tsx
// apps/web/src/app/admin/maintenance/schema-diff/page.tsx
import { Suspense } from 'react';
import SchemaDiffScreen from '@/modules/admin/components/maintenance/schema-diff-screen/SchemaDiffScreen';

const SchemaDiffPage = () => (
  <Suspense>
    <SchemaDiffScreen />
  </Suspense>
);
export default SchemaDiffPage;
```

### Step 9. Frontend — screen module

Two components in `apps/web/src/modules/admin/components/maintenance/schema-diff-screen/`:

- **`SchemaDiffScreen.tsx`** — header, counts (total / additive / mixed / destructive), diff list, "Apply All" button. Skeleton during initial load; "Schema matches" empty state when zero diffs; error card on fetch failure.
- **`DiffRow.tsx`** — one card per `DBTableDiff`. Shows table name, entity class, change-type badge, severity badge, summary (`+2 ±1 -3 columns · -1 index · …`). Expandable to show full SQL in a `<pre>` block. Per-row Apply button.

Generated hooks:

```tsx
import {
  useGetApiAdminCommonDatabaseModelsDiffQuery,
  usePostApiAdminCommonDatabaseModelsApplyDiffMutation,
  usePostApiAdminCommonDatabaseModelsApplyDiffsMutation,
} from '@api/adminApi';
import type { DbTableDiff } from '@/models/DDD/Domain/Base/Repo/DB/Database/Diff/DbTableDiff';
```

Apply call (note the long body key from RTK Query codegen):

```tsx
await applyDiff({
  appPresentationApiAdminCommonDtosDatabaseModelsApplyDiffRequestDto: {
    sqlTableName: tableName,
    disableForeignKeyChecks: true,
  },
}).unwrap();
```

### Step 10. Frontend — severity-aware confirm dialogs

```tsx
if (diff.severity !== 'ADDITIVE') {
  const confirmed = await openConfirm({
    title: t('Apply destructive change to %table%?', { replacements: { table: diff.sqlTableName } }),
    description: t('This change drops or rewrites data and cannot be automatically rolled back.'),
    confirmLabel: t('Apply'),
    cancelLabel: t('Cancel'),
  });
  if (!confirmed) return;
}
```

For Apply All: confirm always, with the count of destructive/mixed diffs as a warning.

### Step 11. Frontend — cache invalidation is automatic

The controller class-level `#[Tag(group: 'Common', name: 'Database Models ', …)]` becomes tag `DatabaseModels` in the generated `*ApiTags.ts`. Both apply mutations invalidate the diff query automatically. **No manual `refetch()` needed.**

---

## Verification Workflow

Once shipped, walk through this against a real environment.

### Backend reachability

```bash
TOKEN=$(curl -sS -X POST "https://<env>/api/admin/common/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PWD\"}" \
  | jq -r '.accessToken')

curl -sS "https://<env>/api/admin/common/databaseModels/diff?debug=true" \
  -H "Authorization: Bearer $TOKEN" \
  -o /tmp/diff.json -w "HTTP %{http_code} | %{size_download}b | %{content_type}\n"
# Expected: HTTP 200 | <bytes> | application/json
```

### Sanity counts on the diff output

```bash
jq '.diffs.elements | length' /tmp/diff.json
jq '.diffs.elements | group_by(.changeType) | map({type: .[0].changeType, n: length})' /tmp/diff.json
jq '.diffs.elements | group_by(.severity) | map({sev: .[0].severity, n: length})' /tmp/diff.json

# Attribute breakdown of MODIFY column diffs — for false-positive hunting
jq '[.diffs.elements[].columnDiffs.elements[]
       | select(.changeKind == "MODIFY") | .changedAttributes]
     | flatten | group_by(.) | map({attr: .[0], count: length}) | sort_by(-.count)' /tmp/diff.json
```

**Triage cheatsheet:**

| Symptom | Likely missing normalisation |
|---|---|
| `defaultValue` top attribute by wide margin on MariaDB | §B — `"NULL"` string normalisation |
| `sqlType` MODIFY on every boolean column | §A — `BOOLEAN` ↔ `TINYINT(1)` |
| `generationExpression` MODIFY on every virtual column | §D — generation expression canonicalisation, or §G if it's the underlying `sqlType` |
| Every `CURRENT_TIMESTAMP`/`UUID()` column flagged MODIFY stripping the default | §E — expression defaults |
| Every nullable trigger column flagged MODIFY | §F — trigger body normalisation |

`targetColumn: null` on every column diff is **the desired state** — that's `#[Ignore]` working correctly.

### Frontend round-trip

1. Visit `/admin/maintenance/schema-diff`. List renders without console errors.
2. Each card shows table name + change-type badge + severity badge + summary.
3. Click expand — SQL preview appears in a `<pre>` block.
4. Click Apply on an ADDITIVE diff — no confirm, mutation fires, list re-renders with the diff removed.
5. Click Apply on a DESTRUCTIVE diff — confirm dialog appears.

### Apply-path correctness

```bash
curl -sS -X POST "https://<env>/api/admin/common/databaseModels/applyDiff" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"sqlTableName": "SomeTable", "disableForeignKeyChecks": true}' | jq '.diffs.elements | length'
# That table should no longer appear in the diff after applying.
```

---

## Known Limitations

- **No atomic rollback.** MySQL DDL is implicit-commit; partial-apply failures leave a mixed state. Mitigation: re-introspection on every apply response surfaces what's still pending.
- **No concurrent-apply guard.** Two admins applying at the same time can race. Rare in practice; defensible to add a re-introspection check inside `applyDiff` per statement.
- **NOT NULL VECTOR ADD on populated table** isn't fully handled — ADD COLUMN fails before backfill can run. Workaround would be ADD-nullable → backfill → MODIFY NOT NULL (currently not implemented).
- **VECTOR index option changes** (`distanceMetric`, `maxNeighbors`) aren't currently diffed. Match key is `(indexType, columns)` — two VECTOR indexes on the same columns with different metrics look identical.
- **`CURRENT_TIMESTAMP` defaults aren't expressible on the target side.** Live wins by design (`defaultIsExpression` short-circuits the compare). Removing such a default requires a manual `ALTER`.
- **Performance.** ~5N `INFORMATION_SCHEMA` queries for N tables. Acceptable for admin endpoints; batchable down to ~5 queries total if it ever matters.

## Adding a New Diff Aspect

Generic recipe — used historically when adding triggers, virtual columns, and the canonical types themselves.

1. **Define the canonical VO** in `src/Domain/Base/Repo/DB/Database/Canonical/DBCanonical{X}.php`. Pure data + typed constructor + a match-key method if matching isn't by name.
2. **Define the diff VO + collection** in `src/Domain/Base/Repo/DB/Database/Diff/DB{X}Diff(s).php` extending `ValueObject` / `ObjectSet`. Don't forget `#[HideProperty]` + `#[Ignore]` on rich-DDD-type `target*` fields.
3. **Build live side** in `DatabaseSchemaIntrospectionService`: a `fetch{X}s()` returning canonical VOs from `INFORMATION_SCHEMA`. Add to `introspectTable()`.
4. **Build target side**: the relevant DDD type (`DatabaseColumn`, `DatabaseIndex`, …) should already expose what you need, or extend it. The target generator runs in `EntityModelGeneratorService::getDatabaseModels()`.
5. **Add comparator** in `DatabaseSchemaDiffService`: `compare{X}s(target, live): DB{X}Diffs`. Match by your VO's match key. Loop both sides; emit ADD / DROP / MODIFY.
6. **Wire into `computeDiffs`**: pull the comparator, attach to `DBTableDiff::${x}Diffs`. Adjust `severity` classifier if your aspect can be destructive.
7. **Add a phase** to the assembler if it has DDL ordering constraints (most do — e.g. triggers must drop before columns, create after).
8. **Add normalisations iteratively.** Run on a real DB, observe the false-positive attribute breakdown (see §Verification), add a rule, re-run.
9. **Add to wire output** by ensuring the collection is a public property on `DBTableDiff` and the OpenAPI schema doesn't choke (run §Step 5 verification).

Caveat: any new rich-DDD-type field needs both `#[HideProperty]` AND `#[Ignore]` or the autodocumenter returns HTTP 500.

## Troubleshooting

| Symptom | Diagnosis |
|---|---|
| `/api/admin/documentation/openApi` returns HTTP 500 after adding diff endpoints | Missing `#[Ignore]` on a rich-DDD-type field. Grep for `target*` properties in the Diff VOs — every one must have BOTH `#[HideProperty]` and `#[Ignore]`. |
| Diff shows hundreds of `defaultValue` MODIFYs on nullable columns | MariaDB `"NULL"` normalisation missing (§B). Framework bug — escalate. |
| Diff shows every table as ALTER on a fresh DB | Either generation-expression normalisation (§D) or trigger normalisation (§F) is missing/regressed. |
| Generated frontend hook missing after SDK regen | The OpenAPI endpoint didn't list the route. Re-run §Step 5; ensure backend deployed. |
| `Only variables can be passed by reference` runtime error in `applyDiffs` | Somewhere a `$set->add(new X(...))` slipped in. `ObjectSet::add()` takes `&...$elements`; assign to a variable first. |
| `applyDiff` succeeds but the table still appears in the next diff | Re-introspection cache hit. `DatabaseSchemaIntrospectionService::invalidateCache()` is called inside `applyDiff` — confirm you're on a framework version where this is wired. |
| Multi-statement SQL crash from `executeStatement()` | A statement made it past `splitMultiStatementSql`. Check for embedded `;` inside string literals (rare but possible in trigger bodies). |

## Conventions

- **Diff is computed on every request.** No persistence; no migration files. Source of truth is the entity classes, snapshot is the live DB.
- **`computeDiffs($entityClasses)` accepts a filter** — pass an array of FQCNs to compute only specific tables. Default null = all entities.
- **`disableForeignKeyChecks = true` is the safe default** for apply. Re-enable only if you have a specific reason and accept the apply-order brittleness.
- **The legacy `generateDatabaseTables` text-dump endpoint** stays in place. Schema Diff is the supported path; the dump is a fallback utility.
- **Hooks belong in frontend code, not backend.** Don't add `beforeApply` / `afterApply` hooks to the diff service — apply ordering is dictated by the phase assembler, not by per-app logic.
