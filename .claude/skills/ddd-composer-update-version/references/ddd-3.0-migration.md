# Moving an app to mgamadeus/ddd 3.0

3.0 has one breaking change: a root filter on an expanded collection no longer cuts that collection down. Everything
else behaves as in 2.71.x. Raise the constraint to `"mgamadeus/ddd": "^3.0"` (and every `ddd-*` module to a release
that allows 3.0), then `composer update mgamadeus/ddd -W`.

## What changed

A root filter on a property of an expanded **collection** selects the roots; each selected root comes back with its
**complete** collection (OData semantics). Before, the filter also removed the non-matching children from the
response.

```
GET /orders/list?expand=orderItems&filters=orderItems.name eq 'Pizza'
```

| | 2.x | 3.0 |
|---|---|---|
| Which orders | orders with a Pizza item | the same |
| Their `orderItems` | only the Pizza items | all items |

To get only the matching children, filter in the expand — it filters the collection and keeps every root:

```
GET /orders/list?expand=orderItems(filters=name eq 'Pizza')
```

Both together give "orders with a Pizza, showing only their Pizza items":
`?expand=orderItems(filters=name eq 'Pizza')&filters=orderItems.name eq 'Pizza'`.

Unchanged:
- To-one paths (`filters=account.email eq …` with `expand=account`): the joined entity is one row either way.
- Several conditions on the same collection in one filter hold for one and the same child
  (`orderItems.name eq 'Pizza' and orderItems.size eq 'L'` = an item that is a large Pizza).
- Expand filters (`expand=x(filters=…)`), expand read rights and the child entity's own `applyReadRightsQuery`
  (applied in the join's ON clause).

## Where it shows up

- **Frontend list screens** that send `filters=<collection>.<field> …` together with `expand=<collection>` and
  display the collection: they now show every child. Move the condition into the expand if only the matching
  children should be shown (keep the root filter if the roots must still be restricted).
- **Root read rights that join a collection** (`applyReadRightsQuery` with `leftJoin` on a to-many relation + WHERE on
  it, the `_rights` alias pattern) — **check these before the update, they widen what non-admins see**: an `expand`
  of that same relation reuses the join. Its WHERE used to trim the expanded collection as well (often to the caller's
  own row); now the collection is complete, restricted only by the child entity's own read rights. Children that must
  stay hidden need rights on the child entity, or the property hidden for non-admins in `mapToEntity`. Example:
  support tickets whose rights join `supportContact.relatedAccounts` on the caller's account — a client
  `expand=supportContact(expand=relatedAccounts)` returned the caller's account only and now returns every account of
  the contact. The same collection was usually reachable without the rights join already (an expand from the caller's
  own account), so audit the collection itself, not only the rights join.
- **Hand-built query builders** that fetch-join a collection (`leftJoin` + `addSelect`) and restrict it in `andWhere`
  to load only some children: put that condition into the join (`leftJoin($path, $alias, Join::WITH, $condition)`).
  A WHERE now only selects the roots.

## Also in 3.0

- `count()` / `countAll()` with an expand counts each root once (`COUNT(DISTINCT id)`); it used to fail with a DQL
  error as soon as an expand was set.
- A query that filters and fetch-joins a collection runs as two steps in one statement (the qualifying root ids,
  then the roots with their joins), with or without a limit — the same shape a paginated query always had.

## Later 3.x releases

- 3.1.0 (2.x line: 2.72.0) — strict `insert()` next to `update()`: `$entity->insert()`, `EntitiesService::insert()`,
  `DBEntity::insert($entity, $depth)`, `DoctrineEntityManager::insert($model, $createRightsQueryBuilder)`. A plain
  `INSERT` (no `ON DUPLICATE KEY UPDATE`, no `ON CONFLICT`), never an update; a unique-key or primary-key collision
  throws `UniqueConstraintViolationException` unchanged. No change to `update()`. Code that implements
  `Dialect\Write\UpsertBuilder` itself adds `insert()`.
- 3.1.1 — the schema diff (`DatabaseSchemaDiffService`, `/databaseModels/diff` and apply) works on PostgreSQL: the
  target is built from the entities in a rolled-back scratch schema and compared with the live schema
  (`current_schema()`) through `PostgresSchemaIntrospectionService`; the apply runs in one transaction with
  `SET LOCAL lock_timeout`. Needs the CREATE privilege on the database (for the scratch schema) and the `ensure()`
  objects on the live database. MariaDB unchanged. The large-table guard message no longer names an app skill.
- 3.1.2 — PostgreSQL schema diff: the container-built `DatabaseSchemaDiffService` (autowired with the MariaDB
  introspection) uses the PostgreSQL introspection, so `/databaseModels/diff` answers 200; tables an extension owns
  (PostGIS `spatial_ref_sys`) are no longer offered as DROP_TABLE.
- 3.1.3 — PostgreSQL registers the spatial DQL functions too (`ST_GeomFromText`, `ST_AsText`, `ST_X`, `ST_Within`,
  `ST_Area`, … — the same names PostGIS has; `Dialect\Query\SpatialDqlFunctions`). DQL with them failed with
  "Expected known function". Geometry values use SRID 0 on both engines.
- 3.2.0 — the issues log becomes configurable (skill `ddd-issues-log-specialist`). Nothing set = as before: the
  `issues` channel reaches the application's own handlers. New: `ISSUES_LOG_TARGET` file/stderr/none (ECS JSON lines,
  new dependency `elastic/ecs-logging`), the capture subscriber for uncaught HTTP 5xx, console, messenger and PHP
  fatals (on by default — an app with its own capture subscriber sets `ISSUES_CAPTURE_ENABLED=0`, or every uncaught
  error is logged twice), log-once, redaction and an 8 KB cap, the additional fields `error.group_hash`,
  `error.issue_hash`, `event.id` and `service.*` (`error.fingerprint*` unchanged), `app:issues:test`. Symfony's own
  "Uncaught PHP Exception" record is dropped for an error the issues log has.
- 3.3.0 — the issues registry (skill `ddd-issues-log-specialist`). Off unless `ISSUES_QUERY_BACKEND` is set: then
  `app:issues:sync` (a cron every minute or `--loop`, one instance) queries the log backend (Loki), keeps one row per
  root cause in the new tables `IssueGroups`, `Issues`, `IssuesJobStates` (generate the models; create the tables like
  any schema change) and posts new causes and regressions to `ISSUES_ALERT_WEBHOOK` — seed mode for the first 24 h,
  explosion guard, `app:issues:health` for the job and the collector. Discovery CLI for agents:
  `app:issues:list|show|search|resolve|ignore|reopen --format=json`. Each issues line gains `error.entry_point`.
- 3.4.0 — issues log, phase C (skill `ddd-issues-log-specialist`). Query backends `elasticsearch` / `opensearch`
  (plain `_search`, ES 7/8 and OpenSearch 2; `ISSUES_ES_KEYWORD_SUFFIX` for keyword-mapped indices) and `sentry`.
  `RuntimeErrorsService` plus `app:runtime-errors:extract` / `probe` with rankingCoach's query bodies, options and JSON,
  configured by `ISSUES_QUERY_*` or the `ES_LOGS_*` fallback. A framework command now yields to an application command
  of the same name, so an app's own `app:runtime-errors:*` stays in charge until it is deleted. New
  `app:issues:forward-sentry` (files → Sentry, read positions in `.issues-sentry-forward.json` next to the logs). No
  new dependency, no schema change.
- 3.5.0 — issues registry per environment (skill `ddd-issues-log-specialist`). `ISSUES_QUERY_ENVIRONMENTS` (JSON
  `{"<environment>": "<selector>"}`, e.g. prod, testing, dev workspaces) replaces `ISSUES_QUERY_SELECTOR` when set; the
  key is environment + hash, and status, alerts, seed mode, explosion guard and job state are per environment. Every
  issues line gains `service.workspace` (`ISSUES_WORKSPACE`, else the dev workspace of the path, else `APP_STAGE`).
  `--environment` on the registry commands, `--workspace` on `app:issues:search`, `{environment}` in
  `ISSUES_ALERT_LINK_TEMPLATE`. **Schema** (regenerate the models, apply the diff): `IssueGroups` + `workspace`,
  `environment` VARCHAR(64), the unique key moves from `groupHash` to (`environment`, `groupHash`); `Issues` +
  `environment`, the unique key moves from `issueHash` to (`environment`, `issueHash`). With one environment the job
  adopts the rows and the job state from before. `IssueGroupsService::findByGroupHash()` / `IssuesService::
  findByIssueHash()` take an optional environment (null = the most recently seen).
- 3.5.1 — issues: frame paths in the hashes are resolved (`realpath`) before they are made relative, and without a
  project directory (a fatal at shutdown) the directory that holds `vendor/` is used. An entry script invoked through a
  symlinked or relative path (`php bin/console` from a linked checkout) gave a different `error.group_hash` than the
  same error invoked through the real path. Groups that were keyed by such an absolute path re-key once. A group whose
  first sample predates `service.workspace` takes the workspace from a newer event.
- 3.5.2 — "generate database tables" (`EntityModelGeneratorService::createOrUpdateDatabaseTablesForEntities()`) works on
  PostgreSQL: it no longer runs MariaDB's `SET FOREIGN_KEY_CHECKS` (it failed with "unrecognized configuration
  parameter"), and on PostgreSQL it returns that dialect's DDL (`ensure()` plus each table's, foreign keys last)
  instead of MariaDB SQL. The PostgreSQL SQL creates (plain `CREATE TABLE`); updating an existing schema is the schema
  diff's job. `getAllEntityClasses()` no longer lists a class twice when one directory is scanned twice.
- 3.6.0 — issues trends (`IssueTrend`); superseded by 3.7.0 before any application used it.
- 3.7.0 — chart types in Core (`DDD\Domain\Common\Entities\Charts`, skill `ddd-entity-specialist`): new
  `DateTimeValue` / `DateTimeValueSequence` (sub-day buckets, `stepSeconds`, `interval`, gap-free
  `fromValuesByTimestamp()`), `DateTimeValueSequences`, and — lifted from the applications with the same names and
  public shape — `TimeInterval`, `AbstractKeyValue`, `IntegerKeyValue`, `StringKeyValue`, `DateValueSequences`. An
  application moves to them by changing its imports. The issues trend uses them: `IssuesQueryBackend::trend(
  TimeInterval $interval, int $stepSeconds, ?string $groupHash): DateTimeValueSequence`, `IssuesTrendService::getTrend(
  $environment|null, $groupHash|null, TimeInterval, ?$stepSeconds)`, `app:issues:trend` JSON `values: [{x, y}]`;
  3.6.0's `IssueTrend` is gone. `RuntimeErrorTrend::fromDateTimeValueSequence()` adapts a series to the runtime-error
  trend shape.
- 3.7.1 — caught message-handler errors reach the issues log: `AppMessageHandler::logShortException()` also logs the
  throwable there (CRITICAL, section MESSENGER, `messenger.custom_message`), `logIssue()` writes rankingCoach's keys
  `messenger.custom_message` / `messenger.message_payload` (before: `message.custom_message` / `message.payload`) and
  no longer loses the payload on an unset typed property; neither throws out of a catch block. `ProcessCLIMessage`
  logs the failing handler's own error (unwrapped, uncaught).
- 3.7.2 — PostgreSQL: the housekeeping deletes ran as raw SQL with unquoted mixed-case identifiers, which PostgreSQL
  folds to lower case — `app:crons:execute` failed with `relation "cronexecutions" does not exist`. Now DQL with typed
  parameters: `CronsService::cleanupCronExecutions()`, `DBLoginToken::deleteExpiredTokens()`,
  `DBCacheScopeInvalidation::deleteExpiredCacheScopeInvalidations()`; `getCronsScheduledForExecution()` binds
  `isActive` as a boolean. Raw SQL in an application: quote identifiers (`$connection->quoteIdentifier()`) or use DQL.
- 3.7.3 — PostgreSQL write and query parity (audit of a production move):
  - `insertOrNull()` / `upsert()` of the raw facade run savepointed: a lost race returns null and the caller's
    transaction stays usable (before: 25P02, AbortedTransactionException at commit). A DO NOTHING upsert (every column
    in the conflict target) returns the existing id — on MariaDB too (`id = LAST_INSERT_ID(id)`; before: 0).
  - `ddd_json_merge_patch` treats non-objects like MariaDB's JSON_MERGE_PATCH (a non-object patch is the result, a
    non-object target is `{}`); before, a stored `[]` failed every later save. **Run the new command** (named in 3.7.4)
    on each PostgreSQL database — it (re)creates the `ddd_*` functions.
  - A save without an id merges onto a UNIQUE key containing a generated column (`virtualCountryId` over
    `IFNULL(countryId, 0)`) instead of a plain INSERT with 23505.
  - Batch upserts: keys equal under `ddd_ci_ai` (Pizza/pizza, Cafe/Café) no longer share one statement (21000).
  - NUL bytes are dropped from text binds on PostgreSQL; LIKE wildcards `%` `_` `\` in filter values and NAME-mode
    fulltext terms are literal; a JSON / Translatable sort keeps MariaDB's NULL ordering.
  - Messenger failures are logged as the handler's own error (unwrapped from HandlerFailedException and
    (Un)RecoverableMessageHandlingException) — once, also when the handler already logged it.
  - `#[UpsertConflictKey]` naming a real key a row does not carry no longer throws: the remaining rules decide.
- 3.7.4 — command names say what they do: `app:db:ensure` (3.7.3) is now
  `app:db:postgres:install-extensions-collation-functions` (PostgreSQL only, idempotent: extensions and the ddd_ci_ai
  collation if missing, the ddd_* SQL functions created or replaced; no alias — run it once per PostgreSQL database
  after 3.7.3). `app:issues:sync` → `app:issues:sync-registry-from-log-backend`, `app:issues:health` →
  `app:issues:check-registry-job-and-collector-health`; the old two names stay as deprecated aliases (running
  deployments and probes keep working) — switch the manifests to the new names.
- 3.7.5 — setting up a PostgreSQL database is documented (README, AGENTS.md, `ddd-database-schema-diff-specialist`
  "Setting up a new PostgreSQL database": role + database → `app:db:postgres:install-extensions-collation-functions`
  → tables; run the command on every deploy, e.g. as an Argo CD PreSync job). The schema diff's PostgreSQL apply
  installs the same objects first (each in a savepoint; one the role may not create is skipped).
- 3.7.6 — PostgreSQL: a DQL UPDATE / DELETE whose WHERE has a correlated subquery on the root alias
  (`… NOT EXISTS (SELECT … WHERE oi.orderId = o.id)`) rendered the root as the bare table name (`Orders."id"`, folded to
  `orders`: "missing FROM-clause entry"). The PostgreSQL walker now names the root by its quoted table name.
- 3.7.7 — crons: a failing Cron never blocks itself or the tick, and every failure reaches the issues log (ERROR,
  section CLI). A command the application does not register is caught before the execution row (one ERROR at most
  hourly, slot advanced); a non-zero exit or timeout is logged with exit code, timeout flag and output tail; the
  bookkeeping stamps are partial writes without validation; every exit after the RUNNING row closes it and advances
  the slot. New `IssuesGroupDiscriminatorInterface`: a throwable can name its own root-cause discriminator
  (`CronFailedException`: one issues group per Cron and kind). `Cron::persistCron()` is now
  `persistCronColumns(string ...$propertyNames)`.
- 3.7.8 — descriptions (OpenAPI, MCP, property and enum docs): inline `{@see Foo::bar()}` / `{@link …}` render as the
  referenced name in backticks (a URL as is) instead of cutting the text at their closing brace
  (`ReflectionDocComment::renderInlineReferenceTags()`). A subclass that rendered them itself keeps working.
- 3.7.9 — issues health: the job records the newest line of any level (`IssuesRegistryJobService::ALL_LOG_LEVELS`),
  so an environment without errors no longer reports "collector silent"; a silent finding carries a hint to prove the
  path with `app:issues:test` (JSON `collectorSilentHint`). New `--job-only`: the exit code reflects the job alone —
  use it for the sync job's liveness probe. Skills note that `bin/console --no-debug` keeps a stale compiled container
  after `composer update` until `cache:clear --no-debug`.
- 3.8.0 — `throwErrors` no longer leaks between units of work: before every consumed Messenger message
  (`FreshWorkerStateMiddleware` → `ServicesThrowErrorsResetter`) and through Symfony's `services_resetter`
  (`kernel.reset`, method `resetThrowErrorsToDefault`) every instantiated `Service` gets the flag back at the default
  its class declares; nothing else of the service is reset. Scoped API on `Service`: `enableThrowErrors()` /
  `disableThrowErrors()` return the value before, `restoreThrowErrors($before)` in `finally`. No worker config needed.
  An app service with its own `reset()` is not affected (the tag names the dedicated method).
- 3.8.1 — the caches snapshot is a stack like the rights snapshot: a nested `deactivateCaches()` /
  `restoreCachesSnapshot()` pair no longer leaves every cache off for the rest of the process. `activateCaches()` is a
  push as well and needs its own restore. Extension point for an app's own cache switch: override
  `getCurrentCacheStates()` / `applyCacheStates()` (parent result plus the app key) instead of the four public methods;
  `resetCachesStateForNewJob()` empties the stack and switches every cache on through `applyCacheStates([])`. **Check
  callers:** exactly one restore per executed path, in `finally` — a restore on a path that never deactivated (or an
  early restore plus a final one) now pops the enclosing window's frame instead of being a no-op.
- 3.8.2 — `deactivateCaches()` / `activateCaches()` switch every key of `getCurrentCacheStates()` through
  `applyCacheStates()`, so an app's own cache switch needs only the two state overrides; an override of
  `deactivateCaches()` / `activateCaches()` that sets the app flag after the parent call keeps working and can go.
- 3.9.0 — issues logging, for applications moving onto it:
  - Committed configuration `config/app/Common/Issues.php` (`Config::get('Common.Issues')`, keys = property names)
    for `IssuesLogSettings` and `IssuesRegistrySettings`; an environment variable overrides a committed value. Secrets
    (`ISSUES_QUERY_TOKEN` / `ES_LOGS_PASSWORD`, `ISSUES_ALERT_WEBHOOK`, `ISSUES_SENTRY_DSN`) are environment only.
  - **Behaviour change:** a DEVELOPMENT Elasticsearch query without `querySelectorDevelopment` throws
    `InternalErrorException` instead of reading the production index.
  - **Behaviour change:** `app:issues:search` / the registry's event search filter the workspace on
    `elasticsearchWorkspaceField` (default `workspace`) instead of the hard-coded `service.workspace`. Elasticsearch
    over the issues log's own lines: set `elasticsearchWorkspaceField` to `service.workspace`.
  - Grouping dimension `GROUP_HASH` (`error.group_hash`) for the runtime-error groups and detail.
  - `sanitizeNumberMinDigits` (default 9, 0 = keep digit runs); the stack traces are sanitised like the message.
  - A failed message's context carries `messenger.retry_count` and `messenger.stamps`.
  - An application subclass `App\Infrastructure\Services\IssuesLogService` is the one instance: the framework id is an
    alias of it (`ApplicationServiceSubclassAliasPass`), with the `issues` logger channel.
- 3.10.0 — client errors (browser, Node, React Native) into the issues log, opt-in:
  `IssuesLogService::logClientIssue(ClientIssueReport, string $section): ClientIssueIngestResult` with the sections
  `LOG_MESSAGE_SECTION_WEB` / `_WEB_SERVER` / `_MOBILE` (`CLIENT_SECTIONS`); wire contract in
  `DDD\Domain\Common\Entities\Issues\Client\`; `ClientIssueReportNormalizer` (caps), `ClientIssuesFingerprintBuilder`
  (hashes), `ClientUserAgentParser`; `IssuesLogRedactor::redactUrl()` / `sanitizeClientText()`, client lists capped
  before the traces; settings `clientIngestEnabled`, `clientIgnorePatterns`, `clientIgnoreUserAgents`,
  `redactPathSegments`, `maxEventBytesWeb`; the alert names the section (`:new: New error [WEB] …` — every section, also
  HTTP / CLI / MESSENGER); `app:issues:test --client`. No schema change.
