<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

use Doctrine\ORM\Configuration;
use PDO;

/**
 * PostgreSQL dialect skeleton (§6 build step 2 explicitly keeps the full dialect — upsert/pagination/DDL/schema —
 * out of scope; that lands in steps 4a/4-9). Only what the harness needs to boot a `pdo_pgsql` connection at all is
 * real: `connectionParams()` (D4.4 — charset must never reach DBAL's pgsql driver, `server_version` maps to
 * `serverVersion`) and a no-op `configureOrm()` (types/functions are step 4/5). `quote()` previews D1's
 * always-quote identifier policy since the harness's own setUp/tearDown SQL already needs it (step 1).
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
}
