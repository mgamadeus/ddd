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
