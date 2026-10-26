<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Repo\DB\Dialect\Identifiers\AlwaysQuoteStrategy;
use DDD\Domain\Base\Repo\DB\Dialect\Identifiers\IdentifierPolicy;
use DDD\Domain\Base\Repo\DB\Dialect\Query\DateBucket;
use DDD\Domain\Base\Repo\DB\Dialect\Query\DefaultOrderTreeWalker;
use DDD\Domain\Base\Repo\DB\Dialect\Query\FulltextMatch;
use DDD\Domain\Base\Repo\DB\Dialect\Query\FulltextScore;
use DDD\Domain\Base\Repo\DB\Dialect\Query\IntDiv;
use DDD\Domain\Base\Repo\DB\Dialect\Query\JsonFieldText;
use DDD\Domain\Base\Repo\DB\Dialect\Query\JsonText;
use DDD\Domain\Base\Repo\DB\Dialect\Query\MariaDbDistinctSubqueryPagination;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PaginationStrategy;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresCosineDistance;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresCosineSimilarity;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresCurrentDate;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresCurrentTime;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresCurrentTimestamp;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresEuclideanDistance;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresField;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresIfNull;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresRand;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresStDistanceSphere;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresStrToDate;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresSubstring;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresSqlWalker;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresVecDistance;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresVecFromText;
use DDD\Domain\Base\Repo\DB\Dialect\Query\RowNumberPagePagination;
use DDD\Domain\Base\Repo\DB\Dialect\Query\StringAggOrdered;
use DDD\Domain\Base\Repo\DB\Dialect\Query\TextCast;
use DDD\Domain\Base\Repo\DB\Dialect\Query\TokenMatch;
use DDD\Domain\Base\Repo\DB\Dialect\Query\TsDayKey;
use DDD\Domain\Base\Repo\DB\Dialect\Query\TsMinuteKey;
use DDD\Domain\Base\Repo\DB\Dialect\Locks\LockService;
use DDD\Domain\Base\Repo\DB\Dialect\Locks\PostgresLockService;
use DDD\Domain\Base\Repo\DB\Dialect\Runtime\AbortedTransactionGuard;
use DDD\Domain\Base\Repo\DB\Dialect\Runtime\BacktickQuoteMiddleware;
use DDD\Domain\Base\Repo\DB\Dialect\Runtime\ValueNormalizerMiddleware;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlRenderer;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer;
use DDD\Domain\Base\Repo\DB\Dialect\Write\PostgresUpsertBuilder;
use DDD\Domain\Base\Repo\DB\Dialect\Write\RawUpsertExecutor;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertBuilder;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertResult;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertSpec;

// Brick\Geo Doctrine Type imports (vendor) — same aliasing as MariaDbDialect, so the identically-named DDD types
// below keep their short names.
use Brick\Geo\Doctrine\Types\GeometryType;
use Brick\Geo\Doctrine\Types\LineStringType as BrickLineStringType;
use Brick\Geo\Doctrine\Types\MultiLineStringType;
use Brick\Geo\Doctrine\Types\MultiPointType;
use Brick\Geo\Doctrine\Types\MultiPolygonType;
use Brick\Geo\Doctrine\Types\PointType as BrickPointType;

use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\BoundingBoxType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\LineStringType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\PointType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\PolygonType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\UnescapedJsonType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\VectorType;
use DoctrineExtensions\Types\PolygonType as BrickPolygonType;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Query;
use PDO;

/**
 * PostgreSQL dialect — identifiers + the runtime middlewares (§6 step 4, read-path slice; upsert/pagination/DQL
 * functions are step 4/5's remaining, write/query-side scope). `connectionParams()` (D4.4 — charset must never
 * reach DBAL's pgsql driver, `server_version` maps to `serverVersion`), `configureOrm()` (D1: registers {@see
 * AlwaysQuoteStrategy} so every table/column/join-column name the ORM emits is quoted in its declared case —
 * PostgreSQL folds an unquoted mixed-case identifier to lowercase, MariaDB does not — and the three PostgreSQL-only
 * DBAL middlewares, §4.5/§4.7), `quote()` (D1's always-quote identifier policy, needed by the harness's own
 * setUp/tearDown SQL since step 1), and `ddlRenderer()`, backed by a fresh {@see IdentifierPolicy} per render run
 * (schema-wide PostgreSQL index/constraint names, §4.5) — one `ddlRenderer()` call is one render run, so its
 * duplicate-name bookkeeping never conflates two unrelated renders.
 */
class PostgresDialect implements SqlDialect
{
    /**
     * One {@see PostgresLockService} per scope, for this process's whole lifetime (§4.12 fix: "a per-process
     * name → depth map" only works per-process when the SAME instance backs every `locks()` call for a scope —
     * `PostgresDialect` itself is already the process-wide singleton {@see DialectResolver} caches, so caching
     * here is caching for the process). Without this, each `locks()` call handed out a FRESH
     * `PostgresLockService`, each opening its OWN direct connection (a fresh PostgreSQL backend/session) lazily
     * on first use: a nested acquire of a name this same request already holds — `AdvisoryLock::synchronized()`
     * calling into nested service code that acquires the SAME name again — went through a SECOND instance whose
     * empty `$depths` map saw no re-entrancy, so it tried `pg_try_advisory_lock` on a BRAND NEW backend session
     * for a key a DIFFERENT session already holds; PostgreSQL advisory locks are session-scoped, so that second
     * session blocks until timeout — the process deadlocks on its own held lock. One instance per scope makes
     * every nested call share one `$depths` map (and one backend session), so {@see AbstractLockService}'s
     * existing re-entrancy actually applies. A second PROCESS still gets its own `PostgresDialect`/cache (PHP has
     * no cross-process statics) and so still genuinely blocks on another process's held lock, as intended.
     *
     * @var array<string, PostgresLockService>
     */
    protected array $lockServicesByScope = [];

    public function name(): string
    {
        return self::POSTGRES;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(self::POSTGRES);
    }

    /**
     * D1 (identifiers) + §4.5/§4.7 (runtime middlewares) + §4.8/§6 step 5 (query side: the DQL function registry,
     * the default output walker, the default tree walker).
     *
     * Middleware order (DBAL folds {@see Configuration::getMiddlewares()} outside-in, last entry wrapping
     * everything registered before it, so the LAST entry here is the one the application calls first):
     * {@see BacktickQuoteMiddleware} runs first, rewriting raw SQL before anything else inspects it;
     * {@see ValueNormalizerMiddleware} wraps the result that comes back; {@see AbortedTransactionGuard} sits
     * innermost, right next to the real driver, so it observes every statement DBAL actually sends and every error
     * the server actually raises.
     *
     * **DQL function registry (§4.8):** `MariaDbDialect::configureOrm()` is left untouched by this step — every
     * name MariaDB already registers (via `DoctrineExtensions\Query\Mysql\*`, Doctrine's built-ins, or the brick/geo
     * and vector functions) keeps rendering exactly its current MariaDB SQL, so the golden suite cannot move. What
     * follows registers PostgreSQL's rendering of those SAME DQL names (so existing DQL text needs no call-site
     * change) plus the names that exist on PostgreSQL only (`TEXT_CAST`, `JSON_TEXT`, `JSON_FIELD_TEXT`,
     * `FULLTEXT_MATCH`, `FULLTEXT_SCORE`, `TOKEN_MATCH`, `TS_MINUTE_KEY`, `TS_DAY_KEY`, `DATE_BUCKET`,
     * `STRING_AGG_ORDERED`, `INT_DIV`) — on its own `Configuration` instance only, since every scope gets a fresh
     * one (`EntityManagerFactory::create()`). `DATE_ADD`/`DATE_SUB` are deliberately left to Doctrine's portable
     * built-ins (§4.8), and `SUBSTRING` is Doctrine's own built-in DQL name overridden here (real failure:
     * `function pg_catalog.substring(timestamp …) does not exist` — Mig §3.6's date-key trick sites still call
     * `SUBSTRING` directly, see {@see PostgresSubstring}). Each custom function is registered under the SAME
     * `addCustom*Function()` category (string/numeric/datetime) MariaDB uses for that name, where MariaDB already
     * registers it — a DQL snippet that parses on one engine must parse on the other, and a function's category
     * governs which grammar positions the parser accepts it in, independent of what SQL it renders.
     *
     * **Default output walker / tree walker:** `AbstractQuery::__construct()` seeds every new query's hints from
     * `Configuration::getDefaultQueryHints()`, so setting `HINT_CUSTOM_OUTPUT_WALKER` / `HINT_CUSTOM_TREE_WALKERS`
     * here makes {@see PostgresSqlWalker} (NULL ordering, D13 row-locking fix) and {@see DefaultOrderTreeWalker}
     * (D13 deterministic root-id tie-breaker) apply to every PostgreSQL query without a per-call hint — exactly
     * what "default" means in the architecture plan's description of both classes. A caller needing a DIFFERENT
     * output walker (the pagination id-page query, {@see RowNumberPagePagination}) overrides the hint explicitly
     * per query instance, which simply replaces this default for that one instance.
     */
    public function configureOrm(Configuration $configuration): void
    {
        $configuration->setQuoteStrategy(new AlwaysQuoteStrategy());
        $configuration->setMiddlewares([
            new AbortedTransactionGuard(),
            new ValueNormalizerMiddleware(),
            new BacktickQuoteMiddleware(),
        ]);

        $configuration->setDefaultQueryHint(Query::HINT_CUSTOM_OUTPUT_WALKER, PostgresSqlWalker::class);
        $configuration->setDefaultQueryHint(Query::HINT_CUSTOM_TREE_WALKERS, [DefaultOrderTreeWalker::class]);

        // ─── String functions ───
        $configuration->addCustomStringFunction('TEXT_CAST', TextCast::class);
        $configuration->addCustomStringFunction('JSON_TEXT', JsonText::class);
        $configuration->addCustomStringFunction('JSON_FIELD_TEXT', JsonFieldText::class);
        $configuration->addCustomStringFunction('TS_MINUTE_KEY', TsMinuteKey::class);
        $configuration->addCustomStringFunction('TS_DAY_KEY', TsDayKey::class);
        $configuration->addCustomStringFunction('DATE_BUCKET', DateBucket::class);
        $configuration->addCustomStringFunction('STRING_AGG_ORDERED', StringAggOrdered::class);
        $configuration->addCustomStringFunction('FIELD', PostgresField::class);
        $configuration->addCustomStringFunction('IFNULL', PostgresIfNull::class);
        $configuration->addCustomStringFunction('STR_TO_DATE', PostgresStrToDate::class);
        $configuration->addCustomStringFunction('SUBSTRING', PostgresSubstring::class);
        $configuration->addCustomStringFunction('VEC_FROM_TEXT', PostgresVecFromText::class);
        $configuration->addCustomStringFunction('FULLTEXT_MATCH', FulltextMatch::class);
        $configuration->addCustomStringFunction('TOKEN_MATCH', TokenMatch::class);

        // ─── Numeric functions ───
        $configuration->addCustomNumericFunction('INT_DIV', IntDiv::class);
        $configuration->addCustomNumericFunction('RAND', PostgresRand::class);
        $configuration->addCustomNumericFunction('FULLTEXT_SCORE', FulltextScore::class);
        $configuration->addCustomNumericFunction('ST_Distance_Sphere', PostgresStDistanceSphere::class);
        $configuration->addCustomNumericFunction('COSINE_DISTANCE', PostgresCosineDistance::class);
        $configuration->addCustomNumericFunction('EUCLIDEAN_DISTANCE', PostgresEuclideanDistance::class);
        $configuration->addCustomNumericFunction('VEC_DISTANCE', PostgresVecDistance::class);
        $configuration->addCustomNumericFunction('COSINE_SIMILARITY', PostgresCosineSimilarity::class);

        // ─── Datetime functions (Mig §3.6: "current time is statement time" — override the built-ins) ───
        $configuration->addCustomDatetimeFunction('CURRENT_TIMESTAMP', PostgresCurrentTimestamp::class);
        $configuration->addCustomDatetimeFunction('CURRENT_DATE', PostgresCurrentDate::class);
        $configuration->addCustomDatetimeFunction('CURRENT_TIME', PostgresCurrentTime::class);

        // ─── Column types (§4.9/§6 step 6 subset): the real Dineful PostgreSQL failure this registers against —
        // "Unknown column type 'cartesian_polygon' requested" the moment ClassMetadataFactory loads an entity
        // with a Cartesian/vector/JSON-override property on a connection whose dialect never ran this method
        // (`Type::addType()`/`Type::overrideType()` are process-wide, not per-connection, so a pure-PostgreSQL
        // process — never touching MariaDbDialect::configureOrm() — needs its own registration of every name the
        // codebase uses). Each DDD custom type (VectorType, the four Cartesian types) already branches its
        // read/write SQL per platform internally (§4.9); registering the SAME type class under the SAME name
        // MariaDbDialect uses is what lets existing `#[DatabaseColumn(type: '…')]`/property-type-driven mappings
        // resolve identically on both engines — no call-site change.
        Type::overrideType('json', UnescapedJsonType::class);

        if (!Type::hasType(VectorType::NAME)) {
            Type::addType(VectorType::NAME, VectorType::class);
        }

        if (!Type::hasType('point')) {
            Type::addType('point', BrickPointType::class);
        }
        if (!Type::hasType('geometry')) {
            Type::addType('geometry', GeometryType::class);
        }
        if (!Type::hasType('linestring')) {
            Type::addType('linestring', BrickLineStringType::class);
        }
        if (!Type::hasType('polygon')) {
            Type::addType('polygon', BrickPolygonType::class);
        }
        if (!Type::hasType('multilinestring')) {
            Type::addType('multilinestring', MultiLineStringType::class);
        }
        if (!Type::hasType('multipoint')) {
            Type::addType('multipoint', MultiPointType::class);
        }
        if (!Type::hasType('multipolygon')) {
            Type::addType('multipolygon', MultiPolygonType::class);
        }

        // ─── Cartesian geometry types (SRID 0) — Point2D / Polyline / Polygon / BoundingBox2D ───
        if (!Type::hasType(PointType::NAME)) {
            Type::addType(PointType::NAME, PointType::class);
        }
        if (!Type::hasType(LineStringType::NAME)) {
            Type::addType(LineStringType::NAME, LineStringType::class);
        }
        if (!Type::hasType(PolygonType::NAME)) {
            Type::addType(PolygonType::NAME, PolygonType::class);
        }
        if (!Type::hasType(BoundingBoxType::NAME)) {
            Type::addType(BoundingBoxType::NAME, BoundingBoxType::class);
        }
    }

    /** D2/§4.8: the single-statement ROW_NUMBER id-page pagination (no second DQL query, no `WhereInWalker`). */
    public function pagination(): PaginationStrategy
    {
        return new RowNumberPagePagination();
    }

    public function connectionParams(array $params): array
    {
        if (array_key_exists('charset', $params)) {
            $charset = $params['charset'];
            if (is_string($charset) && strcasecmp($charset, 'utf8mb4') === 0) {
                throw new DatabaseConfigurationException(
                    'DB_<SCOPE>_CONNECTION_DB_SERVER_CHARSET=utf8mb4 is a MariaDB-only setting; a PostgreSQL scope '
                    . 'must leave it unset — DBAL\'s pgsql driver sends `SET NAMES` for ANY isset charset, "" included.'
                );
            }
            // Never passed through, "" included: isset($params['charset']) alone makes DBAL's pgsql driver send
            // `SET NAMES`, which a non-empty-but-wrong value (or "") would turn into a connection-time error.
            unset($params['charset']);
        }

        if (array_key_exists('server_version', $params)) {
            // DBAL's pgsql driver reads `serverVersion`, not `server_version` (MariaDB leaves it unread: a bare
            // "10.6" without "-MariaDB" would otherwise be read as a MySQL version).
            $params['serverVersion'] = $params['server_version'];
            unset($params['server_version']);
        }

        // Named prepares are already off by default on DBAL's pgsql driver (§2 facts); pinned here defensively
        // against that default ever changing upstream.
        $params['driverOptions'] = ($params['driverOptions'] ?? []) + [PDO::PGSQL_ATTR_DISABLE_PREPARES => true];

        return $params;
    }

    public function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function ddlRenderer(): DdlRenderer
    {
        return new PostgresDdlRenderer(new IdentifierPolicy());
    }

    public function upsertBuilder(): UpsertBuilder
    {
        return new PostgresUpsertBuilder();
    }

    /** $connection is ignored on purpose — see {@see SqlDialect::locks()}'s docblock; the returned instance is
     * cached per scope for this process's lifetime — see {@see self::$lockServicesByScope}'s docblock. */
    public function locks(Connection $connection, string $scope = 'DEFAULT'): LockService
    {
        return $this->lockServicesByScope[$scope] ??= new PostgresLockService($scope, $this);
    }

    // ─── §4.3 app-facing SQL-fragment helpers — new work this build step; each is verified (unit fixtures,
    // `tests/Integration/Query/SqlFacadeTest.php`) to answer the SAME result as MariaDbDialect's rendering for
    // equal input/column values, not merely to look similar.

    public function now(): string
    {
        // Mig §3.6 "current time is statement time": ddd_now() is the SAME STABLE function the generated
        // schema's `created`/`updated` column defaults call (PostgresDdlRenderer::infrastructureStatements()).
        return 'ddd_now()';
    }

    /** @var array<string, string> INTERVAL unit (MariaDB keyword, upper- or lower-case) => make_interval() named argument */
    protected const array MAKE_INTERVAL_ARGUMENT_NAMES = [
        'second' => 'secs',
        'minute' => 'mins',
        'hour' => 'hours',
        'day' => 'days',
        'week' => 'weeks',
        'month' => 'months',
        'year' => 'years',
    ];

    protected static function makeIntervalArgumentName(string $unit): string
    {
        $argumentName = self::MAKE_INTERVAL_ARGUMENT_NAMES[strtolower($unit)] ?? null;
        if ($argumentName === null) {
            throw new \InvalidArgumentException("PostgresDialect: unsupported interval unit \"$unit\" (expected one of: " . implode(', ', array_keys(self::MAKE_INTERVAL_ARGUMENT_NAMES)) . ').');
        }
        return $argumentName;
    }

    public function intervalAgo(string $amountSql, string $unit): string
    {
        return 'ddd_now() - make_interval(' . self::makeIntervalArgumentName($unit) . " => $amountSql)";
    }

    public function dateAdd(string $columnSql, string $amountSql, string $unit): string
    {
        // ($columnSql)::timestamp disambiguates an untyped string literal the same way dateBucket() does
        // ("invalid input syntax for type interval" — Postgres tried the literal + interval overload first).
        return "($columnSql)::timestamp + make_interval(" . self::makeIntervalArgumentName($unit) . " => $amountSql)";
    }

    public function secondsBetween(string $fromSql, string $toSql): string
    {
        // ::timestamp disambiguates two untyped string literals the same way dateAdd()/dateBucket() do
        // ("operator is not unique: unknown - unknown" on a real PG 18 container).
        return "(EXTRACT(EPOCH FROM (($toSql)::timestamp - ($fromSql)::timestamp)))::bigint";
    }

    public function dateBucket(string $columnSql, string $bucket): string
    {
        $truncField = match ($bucket) {
            'week', 'month' => $bucket,
            default => 'day',
        };
        // ($columnSql)::timestamp disambiguates date_trunc(text, timestamp) from its timestamptz overload —
        // an untyped string literal (verified against a real PG 18 container: "function date_trunc(unknown,
        // unknown) is not unique") needs it; a caller passing an already-typed column expression is unaffected,
        // a double cast on an already-`timestamp` value is a no-op.
        return "to_char(date_trunc('$truncField', ($columnSql)::timestamp), 'YYYY-MM-DD')";
    }

    public function stringAgg(string $exprSql, string $separator, string $orderBySql): string
    {
        $quotedSeparator = "'" . str_replace("'", "''", $separator) . "'";
        return "string_agg($exprSql, $quotedSeparator ORDER BY $orderBySql)";
    }

    public function currentDatabase(): string
    {
        return 'current_database()';
    }

    public function rowHash60(array $columnSqls): string
    {
        // sha256() is a PostgreSQL 11+ pg_catalog builtin (no pgcrypto extension needed, verified against the
        // real PG 18 harness) — same 256-bit digest MariaDB's SHA2(…, 256) computes, truncated the same way.
        return 'substring(encode(sha256(convert_to(concat_ws(\'|\', ' . implode(', ', $columnSqls) . "), 'UTF8')), 'hex'), 1, 60)";
    }

    public function updateFromJoin(UpdateFromJoinSpec $spec): string
    {
        // PostgreSQL refuses a relation-qualified SET target ("SET target columns cannot be qualified with the
        // relation name", 42703, verified against a real PG 18 container) — unlike MariaDB's `UPDATE … JOIN`,
        // where every SET target is qualified by convention. The RIGHT-hand side keeps $joinAlias freely.
        $assignments = [];
        foreach ($spec->setAssignments as $columnName => $expressionSql) {
            $assignments[] = '"' . $columnName . '" = ' . $expressionSql;
        }
        $sql = 'UPDATE "' . $spec->targetTable . '" AS ' . $spec->targetAlias
            . ' SET ' . implode(', ', $assignments)
            . ' FROM "' . $spec->joinTable . '" AS ' . $spec->joinAlias
            . ' WHERE ' . $spec->joinOnSql;
        if ($spec->whereSql !== null) {
            $sql .= ' AND (' . $spec->whereSql . ')';
        }
        return $sql;
    }

    public function jsonFieldText(string $columnSql, string $key): string
    {
        $quotedKey = "'" . str_replace("'", "''", $key) . "'";
        return "$columnSql->>$quotedKey";
    }

    public function bytesEqual(string $leftSql, string $rightSql): string
    {
        return "$leftSql IS NOT DISTINCT FROM $rightSql";
    }

    public function boolLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    public function upsert(Connection $connection, UpsertSpec $spec): UpsertResult
    {
        return RawUpsertExecutor::upsertPostgres($connection, $spec);
    }

    public function insertOrNull(Connection $connection, UpsertSpec $spec): ?int
    {
        return RawUpsertExecutor::insertOrNullPostgres($connection, $spec);
    }
}
