<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Repo\DB\Dialect\Identifiers\IdentifierPolicy;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlRenderer;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer;
use Doctrine\ORM\Configuration;
use PDO;

/**
 * PostgreSQL dialect skeleton (§6 build step 2 explicitly keeps the full dialect — upsert/pagination/schema
 * introspection — out of scope; that lands in steps 4/5/7). Step 4a (this revision) completes the CREATE-path
 * schema surface: `connectionParams()` (D4.4 — charset must never reach DBAL's pgsql driver, `server_version` maps
 * to `serverVersion`), a no-op `configureOrm()` (DQL functions/types are step 4/5), `quote()` (D1's always-quote
 * identifier policy, needed by the harness's own setUp/tearDown SQL since step 1), and now `ddlRenderer()`, backed
 * by a fresh {@see IdentifierPolicy} per render run (schema-wide PostgreSQL index/constraint names, §4.5) — one
 * `ddlRenderer()` call is one render run, so its duplicate-name bookkeeping never conflates two unrelated renders.
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

    /** No-op until step 4/5 register PostgreSQL DQL functions and types. */
    public function configureOrm(Configuration $configuration): void
    {
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
}
