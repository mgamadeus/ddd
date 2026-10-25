<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;
use WeakMap;

/**
 * D10: the dialect is resolved from the connection's driver param in
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory::create()}, no round trip, works before the server
 * is reachable (DDL generation, golden tests). `forConnection()` is the fallback `EntityManagerFactory::getDialect()`
 * uses for an entity manager that was rebuilt outside `create()` (`rebuildOnSameConnection()`); it is cached on a
 * `WeakMap<Connection, SqlDialect>` per plan rule 3 (never process-global): the map holds no state that outlives a
 * connection, and both dialect instances are themselves stateless so sharing them across connections is safe.
 */
final class DialectResolver
{
    protected static ?MariaDbDialect $mariaDb = null;
    protected static ?PostgresDialect $postgres = null;

    /** @var WeakMap<Connection, SqlDialect>|null */
    protected static ?WeakMap $byConnection = null;

    public static function forDriver(string $driver): SqlDialect
    {
        return match ($driver) {
            'pdo_mysql', 'mysqli' => self::mariaDb(),
            'pdo_pgsql' => self::postgres(),
            default => throw new InvalidArgumentException("Unknown DBAL driver \"$driver\" for dialect resolution."),
        };
    }

    public static function forConnection(Connection $connection): SqlDialect
    {
        self::$byConnection ??= new WeakMap();
        if (isset(self::$byConnection[$connection])) {
            return self::$byConnection[$connection];
        }

        $driver = $connection->getParams()['driver'] ?? null;
        $dialect = $driver !== null ? self::forDriver($driver) : self::forPlatform($connection->getDatabasePlatform());

        self::$byConnection[$connection] = $dialect;
        return $dialect;
    }

    public static function forPlatform(AbstractPlatform $platform): SqlDialect
    {
        return match (true) {
            $platform instanceof PostgreSQLPlatform => self::postgres(),
            $platform instanceof AbstractMySQLPlatform => self::mariaDb(),
            default => throw new InvalidArgumentException(
                'Unknown database platform "' . $platform::class . '" for dialect resolution.'
            ),
        };
    }

    protected static function mariaDb(): MariaDbDialect
    {
        return self::$mariaDb ??= new MariaDbDialect();
    }

    protected static function postgres(): PostgresDialect
    {
        return self::$postgres ??= new PostgresDialect();
    }
}
