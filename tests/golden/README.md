# T0 golden: MariaDB SQL baseline

Captured from **unmodified mgamadeus/ddd v2.70.10** (commit `a59ef0a`, tag `v2.70.10`, main-line release — before any
PostgreSQL work on branch `postgres`), per the dual-dialect architecture plan
(`agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §5, row "T0 golden"):

> `MariaDbDialect` renders DDL, upsert, pagination and lock SQL for the harness fixtures; compared with
> `tests/golden/mariadb/*.sql` captured from unmodified v2.70.10 by a recording DBAL middleware on the harness
> (2.70.9 executes inline, so a capture from refactored code would confirm itself); re-captured per merged
> main-line release.

Step 2 of the plan's build sequence (the upcoming `MariaDbDialect` refactor) must render **byte-identical** SQL for
the same fixtures, checked without a database via a fake Doctrine driver that reports the production server
version string.

## What is captured

Every SQL statement `tests/Integration` executes against the harness MariaDB (`mariadb:11.8.5`, the
`tests/Integration/docker-compose.yml` container) while running the full integration suite — DDL
(`DROP`/`CREATE TABLE`), upsert/insert/update, `DELETE` (per-test fixture cleanup), pagination queries, and
`SELECT 1` connection pings issued through the recorded connection.

Capture is done by `tests/Integration/Harness/RecordingMiddleware.php` (+ `RecordingDriver.php` /
`RecordingConnection.php` / `RecordingStatement.php`), a **test-only Doctrine DBAL middleware** — nothing under
`src/` was touched. It wraps the DBAL `Driver` → `Connection` → `Statement` chain the standard way
(`Doctrine\DBAL\Driver\Middleware\Abstract*Middleware`) and is wired into the harness's entity manager
(`EntityManagerFactory::DEFAULT`) by swapping in a fresh connection built with `$config->setMiddlewares([...])`,
reusing the same "rebuild the entity manager on a fresh connection" pattern `EntityManagerFactory` itself already
uses — not a change to the factory.

The middleware is a no-op (zero overhead, nothing wired) unless the `DDD_GOLDEN_RECORD=<dir>` env var is set, so
it carries no cost in a normal harness run.

### Output format

One `.sql` file per PHPUnit test, named `<ShortClassName>::<phase>` (sanitised to `__`):

- `<ClassName>__<testMethodName>.sql` — one per test method (`setUp()`'s per-test `DELETE`s are included: they are
  real SQL the harness renders, and the same statements a later dialect must still render).
- `<ClassName>__<testMethodName>__dataset_<name-or-index>.sql` — one per data-provider run of a test method (the
  data set must be part of the id, or re-running the same method with a different data set would silently
  overwrite the previous capture).
- `<ClassName>__setUpBeforeClass.sql` / `<ClassName>__tearDownAfterClass.sql` — the fixture table DDL, which runs
  outside any test method.

Inside a file, statements appear in execution order as:

```sql
<SQL statement text>
-- params: [<JSON array/object of the bound values, in parameter order>]
-- ----------------------------------------
```

A prepared statement's SQL text and its bound parameters are logged together when it is `execute()`d (not at
`prepare()` time), so the params line always reflects what the server actually received. A plain `query()`/`exec()`
call (no placeholders) logs with `"params": []`.

### Normalisation (nondeterminism found and handled)

Two full captures of the suite were diffed byte-for-byte (`diff -rq`) and found identical **after** adding one
normalisation rule in `RecordingMiddleware::normalizeValue()`:

- **`<<DATETIME>>`** — replaces any bound parameter shaped like `YYYY-MM-DD HH:MM:SS[.ffffff]` or
  `YYYY-MM-DDTHH:MM:SS[.ffffff]`. `FilterAgainstDatabaseTest` binds `new DateTime()`-derived "now" values as query
  parameters, which differ run to run (and would differ day to day for anything date-relative). 12 of the 55
  golden files use this placeholder.
- **`<<UUID>>`** — replaces any bound parameter matching the canonical 8-4-4-4-12 hex UUID shape. Not exercised by
  the current T0 fixtures (no fixture entity generates a client-side UUID id), but kept because the architecture
  plan's later fixtures (explicit/generated-id races, `ddd_bytes`, …) are expected to need it; removing it without
  evidence it is unused risks a silent false-pass once those fixtures land.

Only bound **parameter values** are normalised, never SQL text: the dialect comparison this baseline exists for is
about rendered SQL shape, and a placeholder swallowing a difference in the SQL text itself would hide a real
regression. No other source of nondeterminism was found (no random identifiers, no session/connection-id literals,
no generated names in the current fixture set) — if the fixtures grow and a new one shows up, add a case to
`normalizeValue()` and document it here, in the same place as the two above.

## Re-capturing

From a worktree checked out at the version being captured (committed index, tagged release — never mid-refactor):

```sh
composer install --no-interaction               # once, if vendor/ isn't already installed
docker compose -f tests/Integration/docker-compose.yml up -d --wait
rm -rf tests/golden/mariadb
DDD_TEST_DRIVER=pdo_mysql DDD_GOLDEN_RECORD="$(pwd)/tests/golden/mariadb" \
    php -d memory_limit=2G vendor/bin/phpunit -c phpunit.integration.xml
docker compose -f tests/Integration/docker-compose.yml down
```

Verify determinism before trusting a new capture — run it a second time into a scratch directory and diff:

```sh
DDD_TEST_DRIVER=pdo_mysql DDD_GOLDEN_RECORD="$(pwd)/tests/golden/mariadb-recheck" \
    php -d memory_limit=2G vendor/bin/phpunit -c phpunit.integration.xml
diff -rq tests/golden/mariadb tests/golden/mariadb-recheck   # must be empty
rm -rf tests/golden/mariadb-recheck
```

Per the architecture plan, this baseline is **re-captured per merged main-line release** (so it tracks the
"unmodified" reference, not a feature branch), and must never be captured from a branch that has already touched
`src/Domain/Base/Repo/DB/Doctrine/` or the planned `SqlDialect`/`MariaDbDialect` classes — a capture from refactored
code would just confirm itself.

## How the later compare works

Per the plan (build step 1 and the T0 golden row): once `MariaDbDialect` exists (post-refactor, step 2+), a
`--testsuite golden` PHPUnit suite compares its rendered SQL against these files **without a real database** — a
fake Doctrine driver/connection that reports the production server version string, so version-conditional rendering
takes the production branch, not whatever is installed locally. The comparison is byte-identical on SQL text; the
recorded `-- params:` lines are for human review when a diff needs explaining, not part of the automated
byte-identical check (a future refactor may reorder *how* a value reaches the driver without changing the
rendered SQL, e.g. a type conversion moving earlier/later in the stack).

## Provenance

| | |
|---|---|
| DDD version | v2.70.10 |
| Commit | `a59ef0a` ("ft/fb on a TEXT column is supported through the generator's default FULLTEXT index (v2.70.10)") |
| Captured from | `git worktree` at `Core-postgres`, branch `postgres`, HEAD == tag `v2.70.10`, **no changes under `src/`** |
| Harness DB | `mariadb:11.8.5`, `tests/Integration/docker-compose.yml`, port 3317 |
| Suite | `DDD_TEST_DRIVER=pdo_mysql vendor/bin/phpunit -c phpunit.integration.xml` — 43 tests, 93 assertions, all green |
| Golden files | 55 (`tests/golden/mariadb/*.sql`), 522 recorded statements total |
| Determinism | two full captures diffed byte-identical (`diff -rq`) after the `<<DATETIME>>` normalisation above |
