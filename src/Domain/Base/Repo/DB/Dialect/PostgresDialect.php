<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Repo\DB\Dialect\Identifiers\AlwaysQuoteStrategy;
use DDD\Domain\Base\Repo\DB\Dialect\Identifiers\IdentifierPolicy;
use DDD\Domain\Base\Repo\DB\Dialect\Runtime\AbortedTransactionGuard;
use DDD\Domain\Base\Repo\DB\Dialect\Runtime\BacktickQuoteMiddleware;
use DDD\Domain\Base\Repo\DB\Dialect\Runtime\ValueNormalizerMiddleware;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlRenderer;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer;
use DDD\Domain\Base\Repo\DB\Dialect\Write\PostgresUpsertBuilder;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertBuilder;
use Doctrine\ORM\Configuration;
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
     * D1 (identifiers) + §4.5/§4.7 (runtime middlewares). DQL functions/types and the walkers/pagination strategy
     * (query side) and the upsert builders (write side) are step 4/5's remaining scope, not this read-path slice.
     *
     * Middleware order (DBAL folds {@see Configuration::getMiddlewares()} outside-in, last entry wrapping
     * everything registered before it, so the LAST entry here is the one the application calls first):
     * {@see BacktickQuoteMiddleware} runs first, rewriting raw SQL before anything else inspects it;
     * {@see ValueNormalizerMiddleware} wraps the result that comes back; {@see AbortedTransactionGuard} sits
     * innermost, right next to the real driver, so it observes every statement DBAL actually sends and every error
     * the server actually raises.
     */
    public function configureOrm(Configuration $configuration): void
    {
        $configuration->setQuoteStrategy(new AlwaysQuoteStrategy());
        $configuration->setMiddlewares([
            new AbortedTransactionGuard(),
            new ValueNormalizerMiddleware(),
            new BacktickQuoteMiddleware(),
        ]);
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
