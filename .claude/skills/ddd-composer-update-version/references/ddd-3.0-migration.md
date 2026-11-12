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
  `RuntimeErrorsService` plus `app:runtime-errors:extract` / `probe` with the query bodies, options and JSON of an existing runtime-error dashboard,
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
  throwable there (CRITICAL, section MESSENGER, `messenger.custom_message`), `logIssue()` writes the established keys
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
- 3.11.0 — object storage and source maps:
  - `DDD\Infrastructure\ObjectStorage\`: interface `ObjectStorage` (`putObject`, `getObjectContent`, `deleteObject`,
    `objectExists`, `listObjects`, `createTemporaryUrl`), `S3CompatibleObjectStorage` (AWS, Hetzner, MinIO, R2; needs
    `async-aws/s3`, which Core only suggests), `FilesystemObjectStorage`; named stores via
    `ObjectStorageService::getObjectStorage($name)` (null = not configured), configured in
    `config/app/Common/ObjectStorage.php` and `OBJECT_STORAGE_<NAME>_*` (credentials environment only).
  - `DDD\Infrastructure\Logging\SourceMaps\`: `SourceMapParser` (regular and indexed maps), `SourceMapResolver::resolveFrames()`
    → `ResolvedFrames`, reading `sourcemaps/<release>/<chunk path>.map` from the store `sourceMapsStore` (default
    `sourcemaps`); settings `sourceMapsStore`, `sourceMapsCacheDirectory`, `sourceMapsCacheMegabytes`.
    `IssueEvent::getClientFrames()`; `app:issues:show --resolve`.
- 3.12.0 — `ElasticsearchIssuesQueryBackend::search(…, ?int $timeoutSeconds = null)` (a per-call response timeout
  for bulk reads); `setErrorBodyMaxLength()` and `app:runtime-errors:probe --full-error-body`; the runtime-error result
  objects (`RuntimeErrorGroup`, `RuntimeErrorGroupDetail`, `RuntimeErrorOccurrence`, `RuntimeErrorRouteCount`,
  `RuntimeErrorTrendBucket`) document every field for the generated OpenAPI / TypeScript models. Fix: an unconfigured
  database scope (`app:db:read --scope=LEGACY_DB` where no `DB_LEGACY_DB_CONNECTION_*` is set) fails with a
  `DatabaseConfigurationException` naming the variable instead of a `DialectResolver::forDriver()` type error;
  `EntityManagerFactory::isScopeConfigured()`.
- 3.12.1 — docs: the object storage documentation moved into `ddd-service-specialist` › Object Storage; the skill
  `ddd-object-storage-specialist` is gone — applications remove their link to it.
- 3.12.2 — fix: `app:issues:search --workspace=<code>` without `--environment` reads only the environments that can hold
  the workspace (`IssuesRegistryJobService::getEnvironmentNamesForWorkspace()`: named like it or a selector pinning
  `workspace="<code>"`, else every unpinned one) instead of every environment. A failing environment of search and
  trend (a timeout of prod) is a warning plus `failedEnvironments` in JSON; the others still answer, exit 1 only when all
  failed. `IssuesTrendService::findTrendWithFailedEnvironments()` → `IssuesTrendResult`; `getTrend()` returns the partial
  trend and throws only when every environment failed (partial trends are not cached).
- 3.12.3 — fixes: `PhoneNumberConstraintValidator` adds ONE violation for an invalid number (with
  `VALIDATION_POSSIBLE` and a number that is not even possible it added two); `PersonGender::getGender(): ?string` (a
  null gender threw a TypeError). `ContactInfo` and `PersonGender` document their constants and properties.
- 3.12.4 — fix: the source-map resolver is the container service `DDD\Infrastructure\Services\SourceMapResolverService`.
  As `DDD\Infrastructure\Logging\SourceMaps\SourceMapResolver` it lay outside the public service namespaces, so
  `app:issues:show --resolve` (and an admin running `IssuesShow` in-process) failed in a compiled container with
  "service or alias has been removed or inlined". The old name stays as a deprecated subclass for its constants; an
  application that made it public in its own services.yaml removes that line. Core's test suite now checks that every
  `getService(X::class)` target lies in a public service namespace of services.yaml.
- 3.12.5 — docs: `ddd-cli-command-specialist` — `bin/console` (cache:clear, `app:*`, composer scripts) runs as the
  PHP-FPM / worker user, never as root; a root run rebuilds `var/cache` as root and FPM / workers fail with "Could not
  create proxy directory …/doctrine/orm/Proxies" (repair: `chown -R <uid>:<gid> var/cache`).
- 3.12.6 — error events a developer can act on (display-only; group and issue hashes unchanged, pinned by
  `IssuesGoldenHashesTest`): `message` = `<Type>: <message>` (no more "Exception occurred:"; a throwable without a message
  reads `<Type> (no message) at <file>:<line>`), `error.frames` (relative paths, `in_app`, code context of the first 2
  in-app frames; `IssuesFingerprintBuilder::getErrorFrames()` → `IssueErrorFrames`), `error.stack_trace` rendered from
  them, `error.causes`, `route_parameters`, worker fields (`messenger.handler`, `messenger.message_keys`,
  `messenger.attempt`, `messenger.final`, `messenger.origin.*`, `messenger.age_seconds`; `AppMessage::$dispatchOrigin`).
  **Check:** a message class that declares its own `dispatchOrigin` property collides with the new base property. Source
  maps for Next.js server frames (`sourcemaps/<release>/.next/…`) and, for the development release, from the build
  output (`sourceMapsDevelopmentRelease` / `sourceMapsDevelopmentStore`). `SentryEventBuilder` builds frames from
  `error.frames`; the cap order drops `error.fingerprint` first. Loki `--text` search matches message, type and route
  (not the code context). An application that overrides `logThrowable()` / `compileExceptionContext()` keeps its own
  format.
- 3.12.7 — fixes: `messenger.origin.route` is recorded again — `AppMessage::setDispatchOrigin()` reads the container's
  public `request_stack` (the class alias `DDDService::getService('request_stack')` resolved to is removed from a
  compiled container, so the route stayed empty). A frame file outside the project root shows relative to a parent
  (`../public/index.php`) instead of absolute (display only, `IssuesFingerprintBuilder::getDisplayFile()`; the hash
  input `getRelativeFile()` is unchanged). `messenger.stamps` is no longer logged.
- 3.13.0 — the runtime-error detail exposes the issues-log fields per occurrence: `RuntimeErrorOccurrence` gains
  `eventId`, `level`, `section`, `groupHash`, `issueHash`, `entryPoint`, `errorKind`, `errorCode`, `handled`,
  `throwSiteFileName` / `throwSiteLine`, `environment`, `workspace`, `release`, `hostName`, `accountId`, `locationId`,
  `httpMethod`, `httpStatus`, `routeParameters` (`RuntimeErrorKeyValues`), `command`, `exitCode`, `messenger`
  (`RuntimeErrorOccurrenceMessenger`), `errorFrames` (`IssueErrorFrames`) and `causes` (`RuntimeErrorCauses`). Only the
  detail's `recent_occurrences` requests them (`RuntimeErrorDetailAggregationQuery::OCCURRENCE_SOURCE_FIELDS`); the
  groups and trend bodies are unchanged. `buildFromHit()` / `buildFromTopHits()` take an optional
  `ElasticsearchFieldNames`. `app:runtime-errors:extract --group=<key> [--groupingDimension] [--sampleSize=3]` prints one
  group with its newest occurrences.
- 3.13.1 — docs: `ddd-issues-log-specialist/references/admin-error-detail.md`, a guide to building the error detail of an
  admin screen: the runtime-error controller and DTOs, a registry drawer events endpoint (`IssueEvent::$rawJson` →
  `RuntimeErrorOccurrence`, any backend), real occurrence JSON, the drawer layout, code-context rendering and "Copy for
  agent". No code change.
- 3.13.2 — source maps: a resolved file loses leading `../` / `./` segments (Turbopack server maps:
  `../../../../../apps/web/src/route.ts` → `apps/web/src/route.ts`); a map's `names` entry replaces only a minified or
  missing function name and never with an error constructor (`Error` at the throw site) —
  `SourceMapResolverService::getOriginalFunctionName()`.
- 3.13.3 — FK actions on the base of a STORED generated column (the `IFNULL(x, 0)` columns of composite UNIQUE indexes)
  depend on the dialect (`DatabaseForeignKey::getOnUpdateActionForDialect()` / `getOnDeleteActionForDialect()`);
  `DatabaseModel` no longer downgrades them. PostgreSQL gets the declared actions. MariaDB gets RESTRICT only for the
  actions that write the base column (ON UPDATE CASCADE / SET NULL / SET DEFAULT, ON DELETE SET NULL / SET DEFAULT,
  ERROR 1901) and keeps ON DELETE CASCADE, added with `ALGORITHM=COPY` (in place it fails with ERROR 138 under
  `FOREIGN_KEY_CHECKS=0`); the production guard counts that ADD as copy-forcing on a large table. Databases built with
  ≤ 3.13.2: the schema diff proposes `RESTRICT → CASCADE` on these FKs — metadata-only on PostgreSQL, a table copy on
  MariaDB.
- 3.13.4 — `batchUpdate()` (`DoctrineEntityManager::upsertMultiple()`, `PostgresUpsertBuilder::upsertMultiple()`)
  splits a run of rows into statements below 65 535 bound parameters (`UpsertBuilder::MAX_BOUND_PARAMETERS_PER_STATEMENT`),
  inside the existing atomic handling. A large set failed before with PostgreSQL "number of parameters must be between
  0 and 65535" or MariaDB error 1390. Caller-side chunking for the limit can go.
- 3.13.5 — docs and comments only: the repository names no consuming applications, local paths or internal workflows
  any more (neutral wording; the consuming-app registry lives in local notes). `.claude/settings.local.json` is no
  longer tracked. No code change.
- 3.13.6 — docs and comments only: the last mentions of a consuming application from 3.13.5 reworded.
- 3.13.7 — fix: an update of an existing entity keeps its stored ChangeHistory `created`. Before, an update with an id
  but without `changeHistory.createdTime` (every edit form) wrote the save time into `created` on both engines, single
  and batch. The write now renders `created = COALESCE(created, <new>)` (MariaDB `COALESCE(c, VALUES(c))`, PostgreSQL
  `COALESCE(t.c, EXCLUDED.c)` / `COALESCE(c, ?)`): a new row gets the current time, an existing row keeps its own, a
  NULL one is filled. Only code that sets `ChangeHistory::$overwriteCreatedAndModifiedTime` replaces it; the flag is no
  longer hydrated from a payload (`ChangeHistory::setPropertiesFromObject()` keeps its value), so a request body
  cannot set `created` / `updated`. A workaround that copies the stored `createdTime` into the entity before saving can
  be removed.
- 3.13.8 — fix: after `update()` / `insert()` the entity carries the persisted ChangeHistory times instead of the
  payload's — `modifiedTime` the time just written, `createdTime` the stored one (an entity with an id: one extra
  `SELECT created` by id; a new row: the time just written). An update response showed an empty or client-sent
  `createdTime` until the next read. `DBEntity::applyPersistedChangeHistory()`; with `DB_USE_READ_AFTER_WRITE` the
  re-read entity already has them.
- 3.13.9 — fixes: `select=created,updated` keeps `changeHistory` in the output (it was always hidden;
  `SelectOptions::addPropertiesToHideByByJoinPath()` read the wrong array). Composite indexes that abbreviate to the
  same name (`(label, parentId)` and `(labelCode, parentId)` → `uniq_lab_par`) get distinct names: the first declared
  keeps its name, each later one gets `_` + 6 hex characters of its columns. Before, MariaDB silently skipped the
  second index and PostgreSQL refused it ("relation already exists"); after the update the schema diff proposes the
  missing index as an ADD under its new name. Existing index names do not change.
- 3.13.10 — `%env(workspace_vhost:…)%`: when the workspace's broker vhost does not exist, a workspace checkout falls
  back to the `.env` DSN instead of dying with "530 NOT_ALLOWED - vhost <name> not found" (one probe per process
  through Symfony's AMQP `Connection`, 2 s connect timeout; only "vhost not found" falls back; one `error_log` line per
  process). Outside a workspace nothing changes. A fallen-back workspace shares the default vhost's queues — create the
  vhost at provisioning. Contributed in PR #5.
- 3.14.0 — `Cache::acquireLock($key, $ttlSeconds): ?string` / `Cache::releaseLock($key, $token): bool`: a short-lived
  lock in its own keyspace (`ddd.lock.<namespace>.<key>`) — Redis / RedisSentinel `SET NX EX` + compare-and-delete in
  Lua (phpredis and Predis), Apc `apcu_add` (one host only), Chain via its first Redis tier, else APCu. Fix:
  `increment()` on Redis re-armed the counter's TTL on every call, so a counter incremented regularly never expired,
  and a process killed between INCRBY and EXPIRE left it without TTL; the expiry is now set only on creation, in one
  Lua step (APCu already behaved so). A counter used as a lease should move to `acquireLock()`. The test harness has
  a Redis service (`DDD_TEST_REDIS_HOST`).
- 3.14.1 — `Cache::refreshLock($key, $token, $ttlSeconds): bool`: re-arms a lock's TTL only while `$token` still owns
  it (heartbeat for a lease) — Redis / RedisSentinel compare-and-expire in Lua, Apc compare then re-store (two steps),
  Chain on the tier `acquireLock()` uses; a TTL below 1 throws. A counter used as a lease (`increment($key, 0, $ttl)`
  as heartbeat) moves to `acquireLock()` / `refreshLock()` / `releaseLock()`.
- 3.14.2 — Fix: `DDDService::getContainerServiceClassNameForClass()` cached a framework class whose application
  mirror exists at the same path (`DDD\X` → `App\X`) under the service-class-map entry of the application class,
  which is usually absent — a warning on every call, and under a throwing error handler the framework class was cached
  and returned instead of the application class. It now caches the application class.
- 3.14.3 — Fix: module services (`ModuleCompilerPass`) get the tags the container's autoconfiguration assigns to
  their interfaces and parents (`EventSubscriberInterface` → `kernel.event_subscriber`, `ResetInterface` →
  `kernel.reset`, `AbstractController` → `controller.service_arguments` / `container.service_subscriber`,
  `ConstraintValidator` → `validator.constraint_validator`). The pass runs after Symfony resolved the autoconfiguration,
  so `setAutoconfigured(true)` had no effect and a module's event subscriber or resettable service never ran.
  Attribute-based autoconfiguration (`AsMessageHandler`, `AsCommand`) is still not applied to module services.
- 3.14.4 — `MediaItem::$description` is a `TEXT` column without index (was `VARCHAR(255)`): generated image
  descriptions are longer. MariaDB truncated them silently, PostgreSQL rejects the write
  (`SQLSTATE[22001] value too long for type character varying(255)`). Schema change per database:
  PostgreSQL `ALTER TABLE "MediaItems" ALTER COLUMN "description" TYPE text COLLATE "ddd_ci_ai"` (metadata only);
  MariaDB `ALTER TABLE MediaItems MODIFY description TEXT …` (table rebuild). Generate it with the schema diff; an
  application that already declares the column as TEXT has nothing to change.
- 3.15.0 — `excludedTables`: an application lists entity classes in its committed `config/app/Base/Repo/database.php`
  (`'excludedTables' => [Entity::class, …]`) to leave their tables out of its schema. Matched by table (a subclass on
  the same table is covered). The schema diff (no CREATE / ALTER, no DROP of an existing live table), the SQL create
  without a class list and `app:db:copy-to-postgres` leave them out; `app:entity:show-sql <Entity>` still prints the DDL
  with a note; the Doctrine models are still generated. A write through the repository of such a table throws
  `InternalErrorException` naming the table and the key. No key = no change. Neither Core nor a module ships a
  `Base/Repo/database.php`.
- 3.15.1 — Fix: a paginated query (joins + limit/offset) whose to-many joins only filter (the joined alias is not
  selected) keeps its WHERE again in the query that loads the page's roots. Since 3.0 that query ran without the WHERE
  on MariaDB (on PostgreSQL a paginated query always did), so it joined every child row of every root on the page: on a
  large child table one statement read every child row instead of the matching ones and could run into the server's
  statement timeout, and on MariaDB an `ORDER BY` or `GROUP BY` over the joined rows (`ORDER BY COUNT(child.id)`, an
  order by a child column) ranked the page by children the filter excluded. Only a query that fetch-joins a collection
  still loads its roots without the WHERE, so that the collection comes back complete (3.0 semantics unchanged); the
  decision is `DoctrineQueryBuilder::filtersFetchJoinedCollection()` (new). The protected
  `RowNumberPagePagination::buildMainSqlWithoutWhereOrLimit()` is now `buildMainSqlWithoutLimit()`. Still open: a query
  that fetch-joins a collection AND joins another to-many relation only to filter loads that join unfiltered.
  Also in 3.15.1, PostgreSQL: a paginated `GROUP BY` query with joins no longer fails with SQLSTATE 42803 (the page
  rank is ordered as `MIN(…)` in a grouped query), and the page rank goes into the query's own top-level `ORDER BY`
  only, never into one inside a WHERE subselect or an ordered aggregate.
- 3.15.2 — the repository moved to the ddd-framework organisation (github.com/ddd-framework/ddd); links and
  `composer.json` homepage/support updated. No code changes; the package name stays `mgamadeus/ddd`.
- 3.15.3 — Fix: `ModuleCompilerPass` no longer registers abstract classes of a module as services, as Symfony's
  resource loading does. Since 3.14.3 an abstract base of a module's console commands got the `console.command` tag and
  every `bin/console` run tried to instantiate it (fatal error).
- 3.16.0 — `#[QueryOptionsProjection(targetClass: …, propertyMap: ['old' => 'new'])]`: a class without a table (e.g.
  the old shape of a renamed entity) accepts filters / orderBy / select in its own names, validated against the target
  class (also in an expand clause on a load-method property); `AppliedQueryOptions::createProjectedQueryOptions()`
  translates them for the target's repository. PostgreSQL geometry: `#[DatabaseColumn(srid: 4326)]` makes the column
  `geometry(<kind>,4326)` and every write path (upsert, batch, partial update, copy) stores that SRID; default 0 and
  MariaDB unchanged. An explicit `#[DatabaseIndex(TYPE_SPATIAL)]` on a nullable geometry column is now created as a
  GiST index on PostgreSQL (still left out on MariaDB) — such an existing declaration gets its index on the next
  PostgreSQL diff. Object storage: the application logger goes to async-aws, so its retries are logged (info).
- 3.17.0 — `#[ExposePropertyInsteadOfClass]` holds on every surface: the OpenAPI schema documents the exposed
  property's schema in place of the class (contract change: a property typed with such a class is now e.g.
  `type: array, items: number` instead of a `$ref` to an object component, also as an array element — generated TS
  types follow); the serializer emits an empty exposed list as `[]` (was `{}`); a property typed with such a class is
  hydrated from a plain list. `expand()` sets an expand clause as the target class' default QueryOptions only while
  that property loads and restores the previous defaults afterwards — a later load of the same class in the request no
  longer inherits the clause's select, filters, order or page.
- 3.18.0 — Messenger workers as a framework concept, all opt-in. Worker list (`config/system/workers.yaml`:
  consumers per transport, loops) with `app:workers:list` and `app:workers:check` (CI: a consumed transport without a
  consumer entry is an error). Worker monitoring, off unless `Common.Workers` `enabled` / `WORKERS_ENABLED` is set
  and its four tables exist (`WorkerStatBuckets`, `WorkerTransportSamples`, `WorkerProcesses`, `WorkerMessageRuns` —
  an app that does not want them lists them in `Base.Repo.database.excludedTables`): statistics per minute, transport
  and message class, worker processes with heartbeat and inferred stop reason, queue samples, message runs;
  `app:workers:sample` (run every minute), `app:workers:stats`, `app:workers:restart`. `WorkersOverviewService`
  (overview, transport detail, history as chart sequences) and the admin endpoints as `WorkersControllerTrait`, which
  an app mounts with one controller. A monitoring middleware and an event subscriber are registered on every bus
  (they do nothing while the monitoring is off; parameter `ddd.messenger.worker_monitoring: false` removes them).
  Skill: `ddd-workers-specialist`. Fix for every app: an entity string property whose value is the name of a
  ValueObject class (e.g. a stored message class) failed to persist with "Call to a member function
  mapToRepository() on string" — the mapper now decides by the value being an object.
- 3.18.1 — A list declared with nullable elements (`@var (float|null)[]`) keeps a null entry at its position on
  output, on input and in persistence, and OpenAPI documents `items: {…, nullable: true}`; every other list behaves
  as before (null entries dropped on output, refused on input). Schema: a foreign key column no longer gets a plain
  index next to a declared unique (or plain) index over exactly that column, and a class-level single-column
  `UNIQUE` replaces the column's default plain index. An app that declared such a unique index sees one `DROP INDEX
  idx_<column>` per affected table in its next schema diff; an index over several columns changes nothing.
- 3.18.2 — Read performance: the inverse side of a one-to-one relation (the entity whose counterpart holds the
  unique foreign key) is no longer read with every row. Doctrine loaded it with one statement per row and relation,
  on every find and list; it is now read only by an expand (joined) or by touching the lazy-loaded property.
  Behaviour change: such a property is no longer set after a plain load — `isset($parent->detail)` is false until
  the property is read or expanded (reading it loads it, as for every lazy-loaded property). A find by id with a
  `select` hides the properties that were not selected, as a list does (they appeared with their class defaults).
- 3.19.0 — Modules register their services like an application's resource block: `ModuleCompilerPass` runs before
  Symfony's autoconfiguration, so module handlers, commands and tagged services need no application wiring;
  `#[Exclude]` and abstract classes are no services, module interfaces carry their `#[AutoconfigureTag]` to
  implementations, a singly implemented interface gets an alias, `#[AsAlias]` is honoured (an application alias of
  the same id wins). `DDDModule::getLazyServiceNamespaces()`, `DDDKernel::getAdditionalModuleClasses()`, parameter
  `ddd.modules` / `DDDModules::getModuleClasses()`. Check after the update: a module service that an application
  did not cover with a resource block is now autoconfigured by its attributes and interfaces (it may gain a tag),
  and an abstract module class is no longer fetchable by its id. `WorkerStateResetterInterface`: a service
  implementing it is reset before every consumed Messenger job. `Common.objectTypeSerializationAliases` (a moved
  class keeps writing its former `objectType`) and `Common.objectTypeMigrations` now also for single-type
  properties and ObjectSet elements. A relation declared with `LazyLoad::$entityClassName` naming a subclass uses
  that class for the foreign key and for mapping a joined row; expand definitions reflect an application's
  same-path subclass. New: `PublicUrlGuard` (refuses URLs that resolve to private, loopback or link-local hosts),
  `WebsiteHostName`, `Account::isAdminWithAccessToAllResources()`, `AuthService::getAdminAccountOrNull()` /
  `isCurrentSessionAdmin()`, `Config::getConfigRootDirectories()`, `Translatable::CONTEXT_ONE` / `CONTEXT_MANY`.
  Fixes: `Role::setName()` marks the framework's admin roles as admin (a freshly built ADMIN / SUPERADMIN role was
  never admin); `DBAccount::BASE_ORM_MODEL` names the model (every find of the framework's own Account failed);
  the cache's configured `defaultTTL` is read as an integer.
- 3.32.0 — A lazy-loaded property declared with a framework or module entity class loads the application's class of
  that entity (container alias or same path under `App\`) when that class declares a `#[LazyLoadRepo]` of its own:
  a module's `?Account $account` reads the application's account through the application's repository and repo type
  (before: the declared class' repository, e.g. `DBAccount` on a table the application does not have, while the
  application's account is read through `LEGACY_DB`). `LazyLoad::getApplicationClassNameOfTarget()`;
  `LazyLoad::getTargetClassNameForProperty()`, `LazyLoadTrait::getRepoClassForProperty()` and
  `DatabaseModel::resolveForeignEntityClassName()` use it. An application class without its own `#[LazyLoadRepo]`
  leaves the declared class. Action: regenerate the Doctrine models — a framework or module model whose relation
  targets such an entity now points to the application's model (e.g. `use App\…\DBAccountModel` instead of
  `DDD\…\DBAccountModel`, same table). A workaround that redeclares the property with `entityClassName` or overrides
  the framework repository in `services.yaml` keeps working and can go.
- 3.31.2 — `phpunit/phpunit` `^9.5 || ^11.5`: a module whose tests need PHPUnit 11 installs next to the framework.
  `DatabaseVirtualColumn::getColumnNameForVirtualColumn()` returns a name without the `virtual` prefix unchanged (a
  PHPUnit constraint function stood in for `str_starts_with()`, so every name was cut); `src/` no longer uses PHPUnit.
- 3.31.1 — A set an application derives from a module set in another namespace gets its parent's element type: the
  `@property T[] $elements` of the nearest class up to the one declaring the property counts, and `T` is resolved with
  the use statements and namespace of the class that declares it (before, only the derived class' own comment counted
  and the property's `@var BaseObject[]` was resolved in the derived class' namespace, so the API document of 3.30
  failed with `TypeDefinitionMissingOrWrong`). A derived set's own `@property` still wins.
- 3.31.0 — A cron execution whose runner is gone no longer blocks its Cron for hours. `app:crons:execute` closes a
  RUNNING execution older than the runner's timeout per command (`Crons::TIMEOUT_PER_CRON_IN_SECONDS`, 360 s) plus
  `CronExecution::ABANDONED_GRACE_SECONDS` (60 s) as ENDED / FAILED with the reason in its output
  (`CronsService::closeAbandonedCronExecutions()`); before, such a row kept its Cron from running until the cleanup
  deleted it after 4 hours. `cleanupCronExecutions()` no longer deletes RUNNING rows. A runner that receives SIGTERM,
  SIGINT or SIGHUP (pcntl) stops the running command, closes its execution as FAILED naming the signal and advances
  the Cron's slot (`Cron::closeCronExecutionInProgressOnRunnerStop()`), then exits with 128 + signal.
- 3.30.1 — Takes back the inherited trait of 3.30.0: `ReflectionClass::hasTrait()` sees the class' own traits again.
  With 3.30.0 a set that inherits `QueryOptionsTrait` without repeating it got query options and the default `top` of
  50 — an existing list that returned every row came back cut. An application's subclass of a filterable set repeats
  `use QueryOptionsTrait;`. The rest of 3.30.0 stays.
- 3.30.0 — A class an application derives from a framework or module class (same path under `App\`, or a container
  alias) gets what its parent declares: a parent's `QueryOptionsTrait` counts (`ReflectionClass::hasTrait()` sees
  inherited traits); `Foo::getDefaultQueryOptions()`, `setDefaultQueryOptions()` and the snapshot pair of the module
  class go to the application's class, whose repository reads them; `DtoQueryOptions::$baseEntity` is the application's
  class; a class-wide hide (`addStaticPropertiesToHide(true, …)`) covers subclasses; the OpenAPI documenter documents
  the application's class (schema name `App.…`, its added properties) wherever an entity, value object or set of the
  module is named. Action: regenerate the SDK; models of derived classes move from `DDD/…` to `App/…`. A workaround
  that repeats `use QueryOptionsTrait;` or registers a hide on both classes keeps working.
- 3.29.0 — `update()` / `insert()` of an object whose class is a parent class of the repository's entity class (a
  module object built with `new` while the application registered a subclass with its own repository) throws an
  `InternalErrorException` naming both classes; before, it wrote no row and threw nothing. Create such objects with
  `newInstance()`. `BaseObject::uniqueKeyStatic()` treats `0` / `'0'` as an id (`<class>_0`); only null and `''` give
  the bare class name — before, `'0'` and `''` gave the same key.
- 3.28.0 — An expanded property is no longer hidden by a select: `select=` (from the request or a service's default
  select) hid every public property it did not name, including a relation expanded in the same query options, so
  the relation was loaded and then dropped from the output. Expanded properties are now kept on lists, single finds
  and nested expands; unselected, unexpanded properties stay hidden. `SelectOptions::applySelectToDoctrineQueryBuilder()`
  takes an optional `expandOptions` argument. Responses that combine a select with an expand now contain the
  expanded relation.
- 3.27.0 — Raw SQL on both databases. `SqlDialect::byteLength()` and the DQL function `OCTET_LENGTH()` (both
  engines) count bytes (`LENGTH()` counts characters on PostgreSQL). A lock wait timeout on PostgreSQL (SQLSTATE
  55P03, also `FOR UPDATE NOWAIT`) throws `LockWaitTimeoutException`, as on MariaDB; code that caught it as a plain
  `DriverException` still does. On MariaDB `jsonInt()` / `jsonDecimal()` read with `JSON_VALUE` (the document is
  parsed once; an object or array answers NULL instead of 0), consecutive `jsonUpdate()` `set()` / `remove()` calls
  render as one `JSON_SET` / `JSON_REMOVE`, and `locks($connection)` returns one lock service per connection, so a
  lock is released through a later `locks()` call. A class that implements `SqlDialect` itself must add
  `byteLength()`.
- 3.26.0 — `OauthHttpClientService::setOauthConfig(…, fallBackToClientCredentialsWhenRefreshFails: false)`: a failed
  refresh reaches the caller as `OauthTokenRefreshRefusedException` (4xx except 408 / 429; `onInvalidCredentials`
  runs only then) or `OauthTokenRefreshFailedException` (5xx, 408, 429, no answer), with the refresh request's
  exception as previous, instead of falling back to client credentials. The default keeps the fallback.
- 3.25.0 — `SqlDialect::beginTransactionAtIsolationLevel($connection, TransactionIsolationLevel::…)` opens a
  transaction at a level for that transaction only, on both databases (the session's level stays; nothing to
  restore). A class that implements `SqlDialect` itself must add the method. A module's source directory is now a
  resource of the compiled container: a new or removed class file in a module makes a debug container stale, as
  in the application.
- 3.24.4 — Two fixes. (1) An expand whose target has read rights with a subquery no longer breaks when a field of
  another alias starts with the target's base alias (base `site`, `user_site.site_id`): the rights merge renamed the
  field too, so the subquery named a field that does not exist. (2) `#[LazyLoad]` without `repoType` reads a target
  that has no repo of the application's default type (`LAZYLOAD_DEFAULT_REPO_TYPE`, e.g. `LEGACY_DB`) through the
  target's own default repo, at runtime and in the model generator; it used to find no repo and fail. A target that
  has a repo of the default type, and a declared `repoType`, behave as before. Generated models change only in an
  application whose default type is not `DB` and that has such a relation (it now gets its relation mapping).
- 3.24.3 — Fix (PostgreSQL, PHP 8.3): a list with an `ft` / `fb` filter on a fulltext property failed with
  SQLSTATE[HY093] "parameter was not defined" when the fulltext predicate appeared twice in the statement (two
  fulltext filters, or one filter on a list with an expand, which repeats it in the distinct-root subquery). The
  predicate carried a `'\'` literal that PDO before PHP 8.4 misreads while looking for placeholders; its SQL text now
  has no backslash. Search results are unchanged.
- 3.24.2 — Fix: an expand with its own `orderBy` (`expand=children(orderBy=position asc)`) no longer sorts the list.
  Its ordering stood before the list's `orderBy` in the ORDER BY, so the list came back in the order of its children
  (elements without children first). Now the list's `orderBy` comes first and the expand's sorts the children within
  each element; a list without `orderBy` keeps its id order. Also on a page (`top` / `skip`), on both databases.
- 3.24.1 — `tableNames`: the entry of a package entity also covers the application's class of the same name that
  extends it and has an EntitySet class of its own (3.24.0 covered only subclasses sharing the EntitySet class, so
  such an application had to list both classes).
- 3.24.0 — `tableNames`: an application maps an entity class to a table name in its committed
  `config/app/Base/Repo/database.php` (`'tableNames' => [Entity::class => 'TableName']`). The name is verbatim, wins
  over `#[DatabaseTableName]` and is used by the generated model, the schema diff, the SQL create, foreign keys
  pointing to the table and `excludedTables`. A module can drop a `#[DatabaseTableName]` that only served one
  application; that application keeps its table with one config line. Nothing changes without the key.
- 3.23.1 — Worker monitoring: `workerTransportDetail` carries `recentWindowSeconds` (what the counts of its
  `transport` cover), so a transport page labels them without loading the overview. A Cron description longer than
  255 characters is a validation message of `app:crons:upsert` instead of a database error. Guides: registering the
  sampler as a Cron, one monitoring per database, the "sampler is not running" row, what a screen shows while the
  monitoring is off, and where `app:crons:upsert` checks the command.
- 3.23.0 — Raw SQL that runs on MariaDB and PostgreSQL, new on `SqlDialect`: JSON readers over a `JsonPath` with
  literal and bound segments (`jsonText()`, `jsonInt()`, `jsonDecimal()`, `jsonBool()`, `jsonType()`, `jsonLength()`),
  the atomic JSON document update `jsonUpdate()` (`set()`, `setObjectUnlessPresent()`, `remove()`, `mergePatch()`),
  `insertSkippingExistingRows()`, `acquireTransactionLock()` / `releaseTransactionLock()`,
  `setTransactionTimeouts()` / `clearTransactionTimeouts()`, `stringAggDistinct()`, `forShare()`. Virtual columns:
  `CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(col, '$.key')))`, `CHAR_LENGTH(JSON_EXTRACT(col, '$.key'))` and `DATE(col)`
  have a PostgreSQL form. **PostgreSQL behaviour change:** `ddd_json_merge_patch` now merges nested objects (RFC 7396,
  as MariaDB's `JSON_MERGE_PATCH`); before, an object in the patch replaced the stored object of that key. It affects
  mergable JSON columns written with a nested patch. Run `app:db:postgres:install-extensions-collation-functions` on
  deploy (the function is replaced). A class that implements `SqlDialect` itself needs the new methods. Guide:
  `ddd-service-specialist`, `references/raw-sql-on-mariadb-and-postgresql.md`.
- 3.22.0 — **Behaviour change: a relation is mapped onto a loaded entity only when the query of that load fetch-joined it** (an expand, or a join with `addSelect()` in a service's own query). Before, a to-one relation was also set whenever an earlier query of the request had left its target in Doctrine's unit of work — e.g. every row referencing the signed-in account answered the whole account object, without expand and without the target's read rights being applied to that load; a collection an earlier list had expanded appeared the same way. Such a relation now stays unset and lazy-loads on access. **Check in an app:** routes whose clients read a relation the request never expands (the response loses that key — add the expand to the request or call `$entity->expand()` / read the property in the controller), and golden files that contain such a relation. To keep the old mapping while routes are adjusted, set `mapRelationsLoadedByEarlierQueries => true` under `database` in `config/app/Base/Repo.php` (`Base.Repo.database.mapRelationsLoadedByEarlierQueries`). A model passed to a repository's `find()` outside of any load is mapped as before. New: `DoctrineQueryBuilder::getFetchJoinedRelationPaths()`, `getSelectedModelAliases()`.
- 3.21.3 — A virtual column `(CASE WHEN col IN ('A', 'B') THEN col ELSE NULL END)` has a PostgreSQL form without `asPostgres:` (a unique key that holds for some rows only, on both databases — see `ddd-entity-specialist`, virtual columns).
- 3.21.2 — Workers admin screen: UX examples (`references/admin-screen-examples.md` of `ddd-message-handler-specialist`: the questions the screen answers, wireframes, component sketches, wording); the screen guide's layout names the live row per transport and the throughput table. Documentation only.
- 3.21.1 — `EntitySet::getEntityClass()` throws an `InternalErrorException` naming the set and the missing class when no class of the singular name exists (it raised an "Undefined array key" warning and then a TypeError; the warning showed on every filters-definitions build of such a set).
- 3.21.0 — One skill for Messenger: `ddd-workers-specialist` is removed, its content lives in `ddd-message-handler-specialist` (a short SKILL.md with the rules, a task-or-symptom table and the `app:workers:*` command index; the details in `references/`, one file per subtopic). Apps: remove the `.claude/skills/ddd-workers-specialist` symlink and point every reference to it (hooks, app skills, agent files) to `ddd-message-handler-specialist`. No code change.
- 3.20.3 — The model generator writes an inverse relation's target model fully qualified when its short name already means another class in the generated file (a module's model and an application's model of the same path: the foreign key imports the one, the one-to-many or one-to-one inverse side the other — two `use` lines of one short name, a fatal error in the generated model). Every model that was valid before is generated byte-identical. A worker process closed as LOST loses `currentMessageSince` together with `currentMessageClass`.
- 3.20.2 — `QueryOptionsProjection::getProjectionForClass()` answers null for an EntitySet whose entity class cannot be derived from its name (it threw a TypeError since 3.19.0: every filters-definitions build of such a set failed — filters, expands, OpenAPI documents).
- 3.20.1 — Workers console: `app:workers:overview --onlyActive` keeps a transport with a live idle process; `app:workers:history --onlyActive`; `app:workers:queues` exits 1 for a named transport Messenger does not know and tells a transport that could not be created from one that does not exist.
- 3.20.0 — Workers. Console: `app:workers:overview`, `app:workers:transport`, `app:workers:history`, `app:workers:processes`, `app:workers:runs`, `app:workers:queues`, each with `--format=table|json` (JSON = the payload of the matching admin endpoint); `app:workers:stats` also prints the throughput windows. Payloads: `workersOverview` gains `processes` (live), `sequenceWindowSeconds`, `stepSeconds`, `recentWindowSeconds`, `throughputWindows` per transport and in `totals` (300 / 900 / 3600 / 86400 s: `handledCount`, `failedCount`, `workerSeconds`), the transport sequences `waiting` and `processes`; `workersHistory` gains `totals`. **Changed on the wire:** a sequence point of `waiting`, `inFlight`, `processes` without a sample, of the duration sequences without a handled message and of the wait sequences without a dispatch time is `y: null` instead of 0; `interval.startDate` / `endDate` of the workers payloads are ISO 8601 UTC (`UtcTimeInterval`); the overview's total sequences `waiting`, `inFlight`, `processes` add up the samples of one minute instead of the transports' maxima. OpenAPI: `state`, `waitingState`, `displayState` are enums. Monitoring: a consumer entry takes `busySilentSeconds`; a busy process whose operating-system process is gone on the pruning host is closed as LOST at once (setting `closeGoneProcessesOnOwnHost`); a delivery refused with `RejectRedeliveredMessageException` counts as redelivered only; a message dispatched by a handler carries the route or command of the handled message. `DateTimeValueSequence::fromValuesByTimestamp()` takes `$emptyBucketsAreGaps`. No schema change.
- 3.19.1 — OpenAPI: a `Parameter::FILES` property is documented as `type: array, items: { type: string, format:
  binary }` in the `multipart/form-data` content of its operation; the upload class (`UploadedFile`) no longer becomes
  a schema component. No change to request handling. SDK generators that typed the files from that component change
  their output (`@mgamadeus/ddd-web` 0.4.0 reads both forms).
