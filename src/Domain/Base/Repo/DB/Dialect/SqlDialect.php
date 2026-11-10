<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Repo\DB\Dialect\Query\PaginationStrategy;
use DDD\Domain\Base\Repo\DB\Dialect\Locks\LockService;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlRenderer;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertBuilder;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertResult;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertSpec;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\DBAL\Connection;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonDocumentUpdate;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use Doctrine\ORM\Configuration;

/**
 * One facade per database engine (D9, architecture plan `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md`
 * §4.3). This is the step 1/2 slice of the full interface the plan describes: only what
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory::create()} and the integration harness need today
 * (DQL function/type registration, connection-param shaping, identifier quoting). Later build steps (3-9) add
 * `upsertBuilder()`, `pagination()`, `ddlRenderer()`, `introspector()`, `infrastructure()`, `locks()` and the
 * app-facing SQL-fragment helpers as their collaborators are built — adding methods to this interface is additive,
 * never a reason to stub the ones that are not needed yet.
 */
interface SqlDialect
{
    public const string MARIADB = 'mariadb';
    public const string POSTGRES = 'postgres';

    public function name(): string;

    public function capabilities(): Capabilities;

    /** DQL functions, types; walkers/middlewares/quote strategy land as those collaborators are built (step 5+). */
    public function configureOrm(Configuration $configuration): void;

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function connectionParams(array $params): array;

    /** Quotes a single identifier for raw SQL on this engine (`` `x` `` | `"x"`). */
    public function quote(string $identifier): string;

    /** Step 4a: the CREATE-path schema renderer (§4.10). ALTER/introspection land in step 7. */
    public function ddlRenderer(): DdlRenderer;

    /**
     * Step 3/4's write-path collaborator (§4.3). Deviation from the plan's zero-arg signature: the builder is
     * stateless (plan rule 3 — never process-global), but its methods execute against a connection, so every
     * `UpsertBuilder` method takes the calling `DoctrineEntityManager` explicitly instead of the dialect caching
     * one per instance.
     */
    public function upsertBuilder(): UpsertBuilder;

    /**
     * Step 5 (§4.3/§4.8/D2): the dialect's pagination-with-joins-and-limit strategy, used by
     * {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder::applyDistinctSubqueryIfNeededAndGetResult()}
     * for exactly the case that is dialect-specific (every other case — no joins, or no limit/offset — is
     * dialect-agnostic and stays in that method directly).
     */
    public function pagination(): PaginationStrategy;

    /**
     * Step 8's locks collaborator (§4.3/§4.12, D3): named application locks, equal to MariaDB's
     * `GET_LOCK`/`RELEASE_LOCK` contract on both engines. Deviation from the plan's zero-arg `locks(): LockService`
     * signature, for the same reason as {@see upsertBuilder()}: `MariaDbDialect`/`PostgresDialect` are process-wide
     * stateless singletons shared by every scope ({@see DialectResolver} caches one of each for all of them), so
     * neither can know on its own which scope's connection a lock should run against. `MariaDbDialect` locks the
     * given $connection directly (today's behaviour, unchanged). `PostgresDialect` ignores $connection — a
     * session-scoped advisory lock on a PgBouncer transaction-mode connection leaks and loses mutual exclusion —
     * and opens its own direct, non-pooled connection from $scope's `DB_<SCOPE>_DIRECT_HOST`/`DB_<SCOPE>_DIRECT_PORT`
     * instead.
     */
    public function locks(Connection $connection, string $scope = 'DEFAULT'): LockService;

    // ─── §4.3 app-facing SQL-fragment helpers (§6 step 9 subset: the facade app code migrates its remaining
    // MariaDB-only raw SQL against). Every method below returns SQL TEXT ONLY — no execution, no connection — so
    // app code can drop these fragments into its own query strings exactly where today it writes MariaDB SQL
    // literally; `$columnSql`/`$fromSql` etc. are caller-supplied, already-quoted expressions (a column
    // reference, a `?` placeholder, a sub-expression), never raw user input this method would need to escape.
    // MariaDB renderings are byte-identical to what the app already writes (verified against the call sites
    // named in each method's own docblock); PostgreSQL renderings are this build step's new work.

    /** `NOW()` | `ddd_now()` (statement time, Mig §3.6 — same function the generated schema's `created`/`updated` defaults use). */
    public function now(): string;

    /** A moment `$amountSql` `$unit`s before statement time. `$unit` is MariaDB's INTERVAL keyword (MINUTE, HOUR, DAY, …). */
    public function intervalAgo(string $amountSql, string $unit): string;

    /** `DATE_ADD($columnSql, INTERVAL $amountSql $unit)` | `$columnSql + make_interval(<unit> => $amountSql)`. */
    public function dateAdd(string $columnSql, string $amountSql, string $unit): string;

    /** Whole seconds from `$fromSql` to `$toSql`. `TIMESTAMPDIFF(SECOND, a, b)` | `EXTRACT(EPOCH FROM (b - a))`. */
    public function secondsBetween(string $fromSql, string $toSql): string;

    /** `$columnSql` floored to its day/week/month (Monday-start) start, rendered `'YYYY-MM-DD'` on both engines. */
    public function dateBucket(string $columnSql, string $bucket): string;

    /** `GROUP_CONCAT($exprSql ORDER BY $orderBySql SEPARATOR '…')` | `string_agg($exprSql, '…' ORDER BY $orderBySql)`. */
    public function stringAgg(string $exprSql, string $separator, string $orderBySql): string;

    /**
     * {@see stringAgg()} over the distinct values, in their own order:
     * `GROUP_CONCAT(DISTINCT $exprSql ORDER BY $exprSql SEPARATOR '…')` | `string_agg(DISTINCT ddd_text($exprSql), '…' ORDER BY ddd_text($exprSql))`.
     * PostgreSQL orders a DISTINCT aggregate by its own argument only, so there is no separate order expression.
     */
    public function stringAggDistinct(string $exprSql, string $separator): string;

    /**
     * The row lock clause of a SELECT that lets other transactions read and share-lock the rows, not change them:
     * `LOCK IN SHARE MODE` | `FOR SHARE`. Appended at the end of the statement.
     */
    public function forShare(): string;

    /** `DATABASE()` | `current_database()`. */
    public function currentDatabase(): string;

    /** A deterministic 60-hex-char digest of every `$columnSqls` expression, `|`-joined, equal on both engines for equal input values. */
    public function rowHash60(array $columnSqls): string;

    /** Renders a full "UPDATE one table from a join" statement — `UPDATE … JOIN … SET …` has no PostgreSQL form. */
    public function updateFromJoin(UpdateFromJoinSpec $spec): string;

    /** `JSON_UNQUOTE(JSON_EXTRACT($columnSql, '$.$key'))` | `$columnSql->>'$key'`. */
    public function jsonFieldText(string $columnSql, string $key): string;

    /**
     * The scalar at a path of a JSON column as text: a string without its quotes, a number or boolean in its JSON
     * form (`true` / `false`), SQL NULL for a missing path and for JSON null. For an object or array the engines
     * differ in spacing — read those in PHP.
     */
    public function jsonText(string $columnSql, JsonPath $path): string;

    /** {@see jsonText()} as a whole number (`CAST(… AS SIGNED)` | `CAST(… AS bigint)`); NULL when missing. */
    public function jsonInt(string $columnSql, JsonPath $path): string;

    /** {@see jsonText()} as a fixed-point number of the given precision and scale; NULL when missing. */
    public function jsonDecimal(string $columnSql, JsonPath $path, int $precision, int $scale): string;

    /** Whether the value at a path is JSON true; false for any other value, NULL for a missing path or JSON null. */
    public function jsonBool(string $columnSql, JsonPath $path): string;

    /**
     * The type of the value at a path, the same words on both engines: `object`, `array`, `string`, `number`,
     * `boolean`, `null` (JSON null); SQL NULL for a missing path — `jsonType(…) IS NULL` is "the path does not exist".
     */
    public function jsonType(string $columnSql, JsonPath $path): string;

    /** Elements of the array or keys of the object at a path, 1 for a scalar, NULL for a missing path. */
    public function jsonLength(string $columnSql, JsonPath $path): string;

    /**
     * Starts the expression that rewrites a JSON column in one statement (set, remove, merge):
     * `UPDATE … SET column = <jsonUpdate(column)->…->toSql()>`.
     */
    public function jsonUpdate(string $columnSql): JsonDocumentUpdate;

    /** NULL-safe byte equality for a BLOB/`ddd_bytes` column pair: `<=>` | `IS NOT DISTINCT FROM` (4.13's NULL-safe-comparison sweep, extended to bytes). */
    public function bytesEqual(string $leftSql, string $rightSql): string;

    /** `1`/`0` | `TRUE`/`FALSE` — a boolean literal for raw SQL built by string concatenation. */
    public function boolLiteral(bool $value): string;

    /**
     * Raw upsert for app code with no full DDD entity to route through {@see UpsertBuilder} (table name,
     * column→value map via `$spec->columns`, conflict target via `$spec->conflictTargetColumns`) — the plan's
     * `upsert(UpsertSpec): UpsertResult`, with an explicit `$connection` added (same deviation as
     * {@see self::locks()}: the dialect itself is a process-wide stateless singleton, so it cannot own one).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function upsert(Connection $connection, UpsertSpec $spec): UpsertResult;

    /**
     * "Insert or lose the race" for raw app code (same contract as {@see UpsertBuilder::insertOrNull()}, no
     * `DoctrineModel` required): a plain INSERT that returns null on a unique-key collision, throws on every
     * other error.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function insertOrNull(Connection $connection, UpsertSpec $spec): ?int;

    /**
     * An INSERT that leaves out the rows whose unique key already exists and reports the number of rows it wrote:
     * `$insertSql ON DUPLICATE KEY UPDATE t.col = t.col` | `$insertSql ON CONFLICT DO NOTHING`. `$insertSql` is a
     * complete `INSERT INTO … VALUES (…), (…)` or `INSERT INTO … SELECT …`; `$tableName` is its target table and
     * `$columnName` any column of it (both unquoted) — MariaDB assigns that column to itself, which changes nothing
     * and counts 0 for the row.
     * Unlike `INSERT IGNORE` every other error (a value too long, a missing NOT NULL value) still fails.
     */
    public function insertSkippingExistingRows(string $insertSql, string $tableName, string $columnName): string;

    /**
     * A named lock inside the open transaction of `$connection`, in the same name space as {@see locks()}. It runs
     * on the transaction's own connection, so it also works behind a pooler in transaction mode. PostgreSQL holds it
     * until the transaction ends (`pg_try_advisory_xact_lock`, polled until the timeout); MariaDB holds it until
     * {@see releaseTransactionLock()} (`GET_LOCK`). Call {@see releaseTransactionLock()} after the commit or the
     * rollback, in a `finally`.
     *
     * @return bool false when the lock was not free within `$timeoutSeconds`
     * @throws InternalErrorException When `$connection` has no open transaction
     * @throws \Doctrine\DBAL\Exception
     */
    public function acquireTransactionLock(Connection $connection, string $name, int $timeoutSeconds): bool;

    /**
     * Ends a lock of {@see acquireTransactionLock()}: `RELEASE_LOCK` on MariaDB, nothing on PostgreSQL (the end of
     * the transaction released it).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function releaseTransactionLock(Connection $connection, string $name): void;

    /**
     * Limits for the open transaction of `$connection`; null leaves a limit as it is. `$lockWaitTimeoutSeconds`:
     * how long a statement waits for a row lock. `$statementTimeoutSeconds`: how long one statement may run.
     * PostgreSQL sets both for the transaction only (`SET LOCAL lock_timeout` / `statement_timeout`); MariaDB sets
     * them on the session (`innodb_lock_wait_timeout` / `max_statement_time`) until {@see clearTransactionTimeouts()}.
     * A statement over a limit fails with a driver exception: SQLSTATE 55P03 / 57014 on PostgreSQL (the transaction
     * is aborted), error 1205 / 1969 on MariaDB (the statement is rolled back, the transaction stays open).
     *
     * @throws InternalErrorException When `$connection` has no open transaction
     * @throws \Doctrine\DBAL\Exception
     */
    public function setTransactionTimeouts(Connection $connection, ?int $lockWaitTimeoutSeconds, ?int $statementTimeoutSeconds): void;

    /**
     * Puts the limits of {@see setTransactionTimeouts()} back to the server defaults on MariaDB; nothing on
     * PostgreSQL (the end of the transaction did). Call it after the commit or the rollback, in a `finally`.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function clearTransactionTimeouts(Connection $connection): void;
}
