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
use DDD\Domain\Base\Repo\DB\Dialect\Runtime\AbortedTransactionGuard;
use DDD\Domain\Base\Repo\DB\Dialect\Runtime\BacktickQuoteMiddleware;
use DDD\Domain\Base\Repo\DB\Dialect\Runtime\ValueNormalizerMiddleware;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlRenderer;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer;
use DDD\Domain\Base\Repo\DB\Dialect\Write\PostgresUpsertBuilder;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertBuilder;
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
final class PostgresDialect implements SqlDialect
{

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
}
