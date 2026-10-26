# Moving an app to Symfony 7.4 / mgamadeus/ddd 2.70.x

ddd 2.70.0 moved Core to Symfony 7.4. An app that pins `symfony/* 7.3.*` stays on ddd 2.69.x until it moves too.
Collected from the Radbonus (prod since 2026-10-23) and RankingCoach (2026-10-24) upgrades. Work through it in
order; every item cost one of those apps time.

## 1. Constraints and the resolve

- In the app's `composer.json`, every `symfony/*` constraint `7.3.*` → `7.4.*`. Leave `symfony/monolog-bundle ^4.0`,
  `symfony/mime`, `symfony/deprecation-contracts`, `symfony/proxy-manager-bridge` as they are.
- **Remove a `conflict` block on `symfony/runtime|framework-bundle|http-kernel|dependency-injection: >=7.4`.**
  Radbonus, Dineful and Drivebook carried one. It came in with scaffolding, with no recorded reason, and simply pinned
  the app while Core was on 7.3 — `git log -S'"symfony/http-kernel": ">=7.4"'` shows where it came from.
- `composer update -W` — plain, the app's `config.platform.php` (8.3) pins the PHP axis. Never
  `--ignore-platform-reqs`; a targeted `--ignore-platform-req=ext-<name>` for a genuinely missing local extension is
  fine. Core pins `symfony/http-kernel 7.4.*` itself, so an app on PHP 8.4 cannot drift to Symfony 8 underneath.
- Use at least ddd **2.70.1** (2.70.0 breaks the prod container compile, see 3).

## 2. After the install — before trusting any check

- `rm -rf var/cache/<env>`. A container dump left by a failed build keeps its `container.error` tags, and
  `cache:clear` did not always rewrite it. `cache:warmup`, then `lint:container`.
- `git status` for `DB*Model.php`: the post-update `app:generate-doctrine-models-for-entities` regenerates models.
  Restore hand-tuned ones from git (Dineful: then `composer check:write-guards`), and do not commit models regenerated
  from another branch's entity edits (columns the database does not have yet).
- The vendor copy of ddd never matches the tag byte for byte afterwards: the same hook rewrites Core's own
  `DB*Model.php` in `vendor/`. Expected.
- Dev workspaces synced with rsync / IDE `--update`: a vendor file whose archive mtime is older than the one on the
  workspace is skipped and the workspace runs mixed package versions. After a lock change sync `vendor/` with
  `--ignore-times`.

## 3. The checks that catch the real breaks

- **Prod-mode compile from an empty cache** — the check dev mode cannot replace. Symfony 7.4 instantiates constraint
  attributes while compiling the container only when `kernel.debug` is off:
  ```bash
  APP_ENV=prod APP_DEBUG=0 APP_CACHE_DIR=/abs/empty/scratch php bin/console cache:clear     # [OK]
  APP_ENV=prod APP_DEBUG=0 APP_CACHE_DIR=/abs/empty/scratch php bin/console lint:container  # [OK]
  ```
  Run it for every console entry point the app has.
- `debug:router` before vs after — same count, same names. `--format=json` and diff the whole thing.
- OpenAPI before vs after **with identical query parameters** (a comparison with vs without
  `maxRecursiveSchemaDepth` produced a false alarm). Expected difference: `{}` instead of `[]` for empty
  `items` / `properties`.
- Load every class under `src/` once (or run the `ddd-code-inspect-with-qodana` scan): a signature that no longer
  matches a 7.4 interface or base class is a fatal only when that class is loaded.
- `debug:messenger` — see 4.1.

## 4. Breaks and traps, by kind

1. **Message handler silently disconnected (BREAKING).** 7.4's MessengerPass drops a plain `messenger.message_handler`
   tag (e.g. a blanket tag from `services.yaml`) when the same service also gets one from
   `#[AsMessageHandler(fromTransport: …)]`. A wrong `fromTransport` used to be tolerated; now the handler listens only
   on that transport → "no handler", retries, failed queue, no visible error. `debug:messenger` must show
   `(when from_transport=X)` matching the message's routing.
2. **Choice callbacks are lazy (ddd 2.70.1).** `$constraint->choices` is null for a `#[Choice(callback: …)]` until
   something resolves it. Code that reads the allowed values outside validation calls
   `DDD\Infrastructure\Validation\Constraints\Choice::getChoicesResolvedFromConstraint($constraint)` (works for
   Symfony's own Choice too). Never evaluate a callback, or reach DDDService / the container, in a constraint's
   constructor — prod compiles would fatal.
3. **Constraint options array.** A custom constraint calling `parent::__construct([], $groups, $payload)` raises
   "evaluating options in the base Constraint class is deprecated" on every instantiation; pass `null`. Removed in 8.0.
4. **Choice choices passed positionally.** `#[Choice([…])]`, `#[Choice(self::LIST)]` and `#[Choice(options: […])]`
   are deprecated → `#[Choice(choices: …)]`. When rewriting by regex, anchor on `#\[Choice\(`, not on the substring
   `Choice(`.
5. **XML route imports.** `errors.xml` / `wdt.xml` / `profiler.xml` are deprecated shells around `.php` twins. Import
   the `.php` files **with `type: php`**. Up to ddd 2.70.2 an untyped `.php` import was claimed by the ddd attribute
   loader, read as a class, and all 15 profiler / toolbar / error routes vanished silently; 2.70.3 leaves files that
   declare no class to Symfony's PHP loader, but keep the explicit type.
6. **Framework options 7.4 wants set:** `framework.property_info.with_constructor_extractor: true` and
   `framework.profiler.collect_serializer_data: true`.
7. **`config/…/reference.php`** — framework-bundle 7.4 regenerates it on warmup (array shapes for PHP config, ~1,300
   lines). **Add it to `.gitignore`** in every app: `/config/symfony/default/reference.php`
   relative to the app root. If it is already tracked, `git rm --cached` it.
8. **Deprecation noise to expect** (harmless on 7.4, fixed in ddd 2.70.3 where it was Core's): ~500–8,000 Route
   getter calls per cold build from the ddd route loader, the `Choice` options default, Core constraints passing `[]`,
   implicit-nullable parameters. What remains after 2.70.3 is app code (commands without `configure(): void`, the app's
   own constraints and nullables) and, where an app forked Symfony's ErrorHandler, the "internal / final" notices of
   that fork (see 9).

9. **Deleting an ErrorHandler fork un-silences E_NOTICE.** Apps that carried a fork of Symfony's ErrorHandler
   usually also masked notices in `public/index.php` (`error_reporting(... & ~E_NOTICE ...)`). Once both go, the
   stock handler sees every notice: thrown in debug, logged as `php.ERROR` in prod. Before deleting, find the
   pass-by-reference calls with a non-variable argument, typically `$set->add(new X(...))` or
   `$set->add($service->build())` against `ObjectSet::add(&...$elements)`. Qodana reports them as
   `PhpPassByRefInspection`. Fix: assign to a local variable first. Also diff the fork against
   `vendor/symfony/error-handler/ErrorHandler.php` (normalise `\` prefixes and `private`/`protected`): forks
   accumulate app features (Dineful's rendered kernel-escaping fatals as JSON on `?errors_as_json`) that
   disappear with it. (Radbonus had 0 such calls, Dineful 22.)
10. **A cold debug-container build does not fit php-fpm's default 128M.** Measured peak ~170 MB (7.3) and
    ~180 MB (7.4) for a mid-size app; `bin/console` sets 512M itself, FPM does not. Symptom: every `?debug=1`
    request dies with "Allowed memory size … exhausted … DebugClassLoader.php", and `var/cache/dev` holds only
    `…DevDebugContainer.php.lock`. It surfaces when the first request after a vendor/config change comes
    through FPM instead of a console cache reset. Fix: raise `memory_limit` for debug requests on dev
    workspaces (e.g. `ini_set('memory_limit', '512M')` in `public/index.php` behind the dev-workspace + debug
    check), or warm with `bin/console cache:warmup` after every change. Measure your own peak with a cold
    `new DDDKernel('dev', true)` + `boot()` under `APP_CACHE_DIR=<empty dir>` and `memory_get_peak_usage(true)`.

## 5. Runtime notes (no breakage)

- The first `?debug=1` request after an upgrade can run out of memory while it builds the container — see 4.10.

- **Long console runs in debug mode on ddd ≤ 2.70.2 can exhaust memory.** The debug error handler keeps every
  deprecation it sees, and the ddd route loader and `Choice` produced thousands per container / route load. RC's
  22-turn agent recording died with "Allowed memory size … exhausted" in `PhpFilesAdapter`. Use ddd ≥ 2.70.3, or
  run long jobs with `--no-debug`.
- Failed-queue envelopes no longer carry a stack trace (`ErrorDetailsStamp`).
- AMQP retry and heartbeat behaviour changed slightly.
- SymfonyRuntime auto-detects FrankenPHP worker mode; a custom runtime must check `FRANKENPHP_WORKER` first.
  `APP_RUNTIME_OPTIONS` arrays pass through.
- Deploy window: Radbonus saw 2× 500 "Could not create proxy directory …/Proxies" in the second its cache was being
  rebuilt under traffic. Warm the new cache before switching traffic to it.

## 6. Related changes in the same ddd line

- 2.69.0 — only `tokenType: access` tokens log in; apps that mint access tokens themselves must set the claim, and an
  app's own cookie / bearer authenticators should call `AuthService::isAccessTokenPayload()`.
- 2.70.3 — a filter `eq null` / `ne null` now compares with IS [NOT] NULL like `'NULL'`. Before, a bare `null` was
  bound as a parameter (`col = NULL`) and matched no row. Code that used bare `null` returned nothing until now.
- 2.70.5 — `AccountsService::find(null)` loads the authenticated account (it used to crash), and its docblock no
  longer announces a phantom `$accountId` (that and 32 more phantom `@param`s raised DebugClassLoader deprecations
  in every overriding app class). `AppMessage::dispatch(array $stamps = [])` takes Messenger stamps. An entity named
  like a DQL reserved word (`Order`) gets `MODEL_ALIAS = 'OrderModel'`. Apps no longer need their cweagans patches
  for these — drop them (see "Consumers never patch mgamadeus/*" in the skill).
- 2.70.7 — a quoted filter literal is unescaped: `'O\'Brien'` is `O'Brien` (it used to stay `O\'Brien` and match
  nothing), `\\` is one backslash, any other backslash is literal. `FiltersOptions::createExpression($property,
  $operator, $value)` builds an expression from a runtime value — use it instead of concatenating the value into a
  filter string.
- 2.70.8 — `#[PreserveOnOmission]` on an entity property: an update payload that omits the key leaves the stored
  column untouched, and the generated model property carries no PHP default (the SQL `DEFAULT` stays). The entity
  constant `PRESERVED_ON_OMISSION` is honoured the same way (deprecated). Apps that removed model defaults by hand
  regenerate and commit the generator output — no restores.
- 2.70.9 — bug-fix release; the consumer-visible changes:
  - **`ft` / `fb` on a property without a declared FULLTEXT index is a 400 by default** (it was the database's 1191,
    a 500). An app whose index exists without a `#[DatabaseIndex]` / `Translatable(fullTextIndex: true)` declaration
    sets `ddd.queryoptions.strict_fulltext_operators: false`, or declares the index.
  - Filter values are validated by column kind: `id eq '#12'`, `amount eq 'abc'`, `created eq '2026-10'` and any
    comparison on a JSON column or an exact `eq` on a plain Translatable are 400s; `eq 'true'` on a bool now matches
    the true rows. Wildcards (`'*x*'`) keep their LIKE. A Translatable with `fullTextIndex: true` compares and sorts
    on its search column (an exact `eq` matches single-language rows; the sort order changes in case / accents).
  - `updatePartialIgnoringRights()` on a row deleted meanwhile writes nothing and returns null (it inserted a ghost
    row); through the entity it is a silent no-op.
  - Batch upserts (`batchUpdate()`) write each row's initialized fields only and store NULL geometries, vectors and
    replace-translations correctly; several statements run atomically.
  - Workers roll back (and log) a transaction a handler left open; a `sync://` dispatch no longer resets the
    request's state. `app:db:read` runs in a READ ONLY transaction.
  - Public symbols nothing used were removed (list in the release commit "Dead code: …").
- 2.70.10 — a TEXT / MEDIUMTEXT / LONGTEXT column counts as fulltext-capable through the FULLTEXT index the generator
  gives it by default; 2.70.9 answered 400 to `ft` / `fb` there although the index existed.
- 2.70.11 — every JWT is signed and verified with `JWT_HASH_KEY` / `JWT_HASH_METHOD`. `AUTH_JWT_HASH_KEY` /
  `AUTH_JWT_HASH_METHOD` are read only while `JWT_HASH_*` is empty (one deprecation per process) and go in the next
  minor: make sure `JWT_HASH_*` is set (same values), then delete the `AUTH_JWT_HASH_*` lines and vault fields.
- 2.71.0 — PostgreSQL as a second dialect next to MariaDB. A MariaDB / `pdo_mysql` app needs no change: the
  generated SQL is byte-identical and no public signature changed. What applies when you touch the new surface:
  - **PostgreSQL scope** (`pdo_pgsql`): set `DB_<SCOPE>_DIRECT_HOST` (and `DB_<SCOPE>_DIRECT_PORT` when it differs
    from the pooled port) — the direct, non-pooled host named locks (`LockService`) run on; without it the scope
    refuses to boot. Restart every worker after deploying a release that changes the query walkers: the Doctrine query
    cache keys on the walker class and a running worker keeps the old SQL.
  - **Code written against the pre-release `postgres` branch**: `UpsertSpec::$columns` is a `ColumnWrites` set —
    `new UpsertSpec($table, ColumnWrites::fromColumnWrites(...$columnWrites))`, emptiness is `->count() === 0`. The
    `DdlRenderer` methods return `DdlStatements` (`withPhase()`, `withoutPhase()`, `sortedByPhase()`,
    `getSqlList()`; the static `DdlStatement::sortByPhase()` is gone), `LockKey::internalKey()` returns an
    `AdvisoryLockKeyPair`, and the copy service returns `CopyTableResults`.
  - **Unreleased installs** (`dev-*` or path repository), on every driver: the guard refuses a connection to a
    production host / port read from `DB_PRODUCTION_HOSTS` / `DB_PRODUCTION_PORTS` (comma-separated), and refuses
    every connection while both are empty (fails closed). A dev or spike checkout on a branch or path install sets
    them in `.env.local`; a tagged release is never checked.
  - **`#[DatabaseTrigger]` without its source file** fails model SQL generation on both dialects (it used to render
    no trigger): `{Entity}{Order}{Operations}Trigger.sql` next to the entity's repo, plus `….pgsql.sql` for a
    PostgreSQL scope.
  - **A paginated query with to-many joins on PostgreSQL** (`$top` / `$skip` with an `$expand` of a collection): the
    filter decides which roots are on the page, and those roots come back with ALL their children. MariaDB applies
    the filter to the children as well, so a filter on a child property returns only the matching children there.
- 2.71.1 — PostgreSQL fixes: a paginated query with to-many joins returns the page's roots in the requested order
  (2.71.0 returned them in id order); `SqlDialect::stringAgg()` and DQL `STRING_AGG_ORDERED` aggregate ints and
  booleans in MariaDB's text form through new `ddd_text()` functions (part of `ensure()`: a database set up with
  2.71.0 gets them by applying the `ddd_text` statements `app:entity:show-sql` prints, or by the schema phase of
  `app:db:copy-to-postgres`); `STRING_AGG_ORDERED` without `GROUP BY` no longer gets an `ORDER BY id`; DQL `DATE_BUCKET(x, 'month')`
  gives the first of the month (`YYYY-MM-01`) like the facade. MariaDB unchanged.
- 2.71.2 — a list or expand loaded with a `select` no longer puts its partial entities into the entity registry under
  the key of the plain find by id. A later `find($id)` — in the same request, or for up to the entity's
  `#[EntityCache(useExtendedRegistryCache: true)]` TTL in any request of the same container — returned the entity with
  every unselected property missing (settings read from it silently fell back to their defaults). No app change;
  restart the containers after the update to drop already poisoned APCu entries.
