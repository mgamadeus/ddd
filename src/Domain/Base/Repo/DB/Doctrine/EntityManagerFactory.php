<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

// Infrastructure / Framework imports
use DDD\Domain\Base\Repo\DB\Dialect\DatabaseConfigurationException;
use DDD\Domain\Base\Repo\DB\Dialect\DialectResolver;
use DDD\Domain\Base\Repo\DB\Dialect\Guard\UnreleasedCodeGuard;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\ORM\ORMSetup;

use RuntimeException;

class EntityManagerFactory
{
    /** @var DoctrineEntityManager[] */
    protected static array $instances = [];

    /** @var SqlDialect[] Resolved in create(); getDialect() falls back to DialectResolver::forConnection() */
    protected static array $dialects = [];

    public const string SCOPE_DEFAULT = 'DEFAULT';

    public const string DEFAULT_NAMESPACE = 'DoctrineProxies';

    public const string SCOPE_LEGACY_DB = 'LEGACY_DB';

    /**
     * The entity manager of a scope, created on first use and kept healthy — but never replaced while its connection
     * is inside a transaction: a new entity manager (or a reconnected connection) would run the caller's later writes
     * in autocommit, outside the transaction, which then dies uncommitted.
     *
     * - closed entity manager (an ORM wrapInTransaction failure closes it before its rollBack): rebuilt on the SAME
     *   connection while a transaction is active, recreated otherwise;
     * - the unthrottled desync heal ({@see DoctrineEntityManager::healDesyncedTransactionState()}) runs first, because
     *   it can close the connection and zero the nesting level;
     * - inside a transaction the ping is skipped: a lost connection surfaces as an exception on the caller's next
     *   statement. Outside one, a failed ping recreates the entity manager (any DBAL exception: ProxySQL / failover
     *   errors arrive as plain driver exceptions), so a long CLI command survives a failover.
     *
     * @param string $scope Connection scope, e.g. self::SCOPE_DEFAULT
     * @return DoctrineEntityManager
     */
    public static function getInstance(
        string $scope = self::SCOPE_DEFAULT
    ): DoctrineEntityManager {
        if (!isset(self::$instances[$scope])) {
            self::create($scope);
        }
        if (!self::$instances[$scope]->isOpen()) {
            if (self::$instances[$scope]->getConnection()->isTransactionActive()) {
                self::rebuildOnSameConnection($scope);
            } else {
                self::create($scope);
            }
        }
        // Unthrottled transaction-state desync heal (DBAL nesting counter vs native PDO inTransaction) —
        // MUST run before/independent of the throttled ping below: the SELECT-1 ping succeeds on a desynced
        // connection and would never detect it. See DoctrineEntityManager::healDesyncedTransactionState().
        self::$instances[$scope]->healDesyncedTransactionState();
        if (self::$instances[$scope]->getConnection()->isTransactionActive()) {
            return self::$instances[$scope];
        }
        // Check if the connection is still pingable
        if (!self::$instances[$scope]->isConnectionActive()) {
            self::create($scope);
        }

        return self::$instances[$scope];
    }

    /**
     * Replaces a closed entity manager by a new one on the same connection, configuration and event manager, so the
     * connection's open transaction stays the one the caller commits or rolls back.
     *
     * @param string $scope Connection scope
     * @return void
     */
    protected static function rebuildOnSameConnection(string $scope): void
    {
        $closedEntityManager = self::$instances[$scope];
        self::$instances[$scope] = new DoctrineEntityManager(
            $closedEntityManager->getConnection(),
            $closedEntityManager->getConfiguration(),
            $closedEntityManager->getEventManager()
        );
    }

    /**
     * The scopes that have an entity manager in this process — never creates one.
     *
     * @return string[]
     */
    public static function getInstantiatedScopes(): array
    {
        return array_map('strval', array_keys(self::$instances));
    }

    /**
     * The entity manager of a scope if this process has one, without creating, healing or pinging it.
     *
     * @param string $scope Connection scope
     * @return DoctrineEntityManager|null
     */
    public static function getExistingInstance(string $scope): ?DoctrineEntityManager
    {
        return self::$instances[$scope] ?? null;
    }

    /**
     * @return void Iterates through all instances and calls clear(), this clears Doctrine unit of work caches
     */
    public static function clearAllInstanceCaches(): void
    {
        foreach (self::$instances as $instance) {
            $instance->clear();
        }
        gc_collect_cycles();
    }

    /**
     * Closes the database connection of every entity manager instance (all scopes) that has NO active transaction,
     * so the next statement on it opens a fresh session — a new REPEATABLE READ snapshot, no session state inherited
     * from earlier work. The DBAL connection reconnects lazily; nothing else has to be recreated. A connection inside
     * a transaction is left alone: closing it would silently roll the transaction back.
     *
     * Used by {@see \DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler::renewDatabaseConnection()}: before a
     * consumed worker job and, in agent loops, before a tool call that reads a baseline it then writes against.
     */
    public static function renewAllConnections(): void
    {
        foreach (self::$instances as $instance) {
            $connection = $instance->getConnection();
            if (!$connection->isTransactionActive()) {
                $connection->close();
            }
        }
    }

    protected static function getDBConfigForScope(string $scope = self::SCOPE_DEFAULT): array
    {
        $config = [
            'driver' => Config::getEnv("DB_{$scope}_CONNECTION_DRIVER"),
            'host' => Config::getEnv("DB_{$scope}_CONNECTION_HOST"),
            'user' => Config::getEnv("DB_{$scope}_CONNECTION_USER"),
            'password' => Config::getEnv("DB_{$scope}_CONNECTION_PASSWORD"),
            'dbname' => Config::getEnv("DB_{$scope}_CONNECTION_DB_NAME"),
            'server_version' => Config::getEnv("DB_{$scope}_CONNECTION_DB_SERVER_VERSION"),
            'charset' => Config::getEnv("DB_{$scope}_CONNECTION_DB_SERVER_CHARSET"),
        ];
        if (Config::getEnv("DB_{$scope}_CONNECTION_HOST_PORT")) {
            $config ['port'] = Config::getEnv("DB_{$scope}_CONNECTION_HOST_PORT");
        }
        $proxyGenerationMode = AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS;
        if ($proxyGenerationConfig = Config::getEnv("DB_{$scope}_DOCTRINE_PROXY_GENERATION_MODE")) {
            $proxyGenerationMode = match ($proxyGenerationConfig) {
                'AUTOGENERATE_FILE_NOT_EXISTS' => AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS,
                'AUTOGENERATE_ALWAYS' => AbstractProxyFactory::AUTOGENERATE_ALWAYS,
                'AUTOGENERATE_NEVER' => AbstractProxyFactory::AUTOGENERATE_NEVER,
                'AUTOGENERATE_EVAL' => AbstractProxyFactory::AUTOGENERATE_EVAL,
                'AUTOGENERATE_FILE_NOT_EXISTS_OR_CHANGED' => AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS_OR_CHANGED
            };
        }
        return ['connectionParams' => $config, 'doctrineConfig' => ['proxyGenerationMode' => $proxyGenerationMode]];
    }

    public static function create(string $scope = self::SCOPE_DEFAULT)
    {
        $isDevMode = false;
        // Specify the directory where Doctrine should save proxy classes
        $proxyDir = DDDService::instance()->getCacheDir(false) . '/doctrine/orm/Proxies';
        if (!is_dir($proxyDir)) {
            // Attempt to create the directory with recursive flag set to true
            if (!mkdir($proxyDir, 0775, true)) {
                throw new RuntimeException(sprintf('Could not create proxy directory "%s".', $proxyDir));
            }
        }
        $cache = Cache::instance('DOCTRINE')->getCacheAdapter();
        $metaDataCache = Cache::instance('DOCTRINE_METADATA')->getCacheAdapter();
        $configSettings = self::getDBConfigForScope($scope);
        self::assertScopeIsConfigured($scope, $configSettings);

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__ . '/../Domain'], // Adjusted path for correctness
            $isDevMode,
            $proxyDir, // Now passing the specified proxy directory
            $cache
        );
        $config->setMetadataCache($metaDataCache);
        $config->setClassMetadataFactoryName(ClassMetadataFactory::class);

        $dialect = DialectResolver::forDriver($configSettings['connectionParams']['driver']);
        $configSettings['connectionParams'] = $dialect->connectionParams($configSettings['connectionParams']);
        UnreleasedCodeGuard::assertAllowed($configSettings['connectionParams']);
        // §4.12 boot guard (D3): named locks on PostgreSQL run on a dedicated, non-pooled connection — a
        // session-scoped advisory lock on a PgBouncer transaction-mode connection leaks and loses mutual
        // exclusion. Checked here, at scope boot, rather than lazily inside PostgresLockService, so a
        // misconfigured scope fails loudly before anything tries to take a lock on it.
        if ($dialect->capabilities()->isPostgres() && !Config::getEnv("DB_{$scope}_DIRECT_HOST")) {
            throw new DatabaseConfigurationException(
                "DB_{$scope}_DIRECT_HOST is required on PostgreSQL: named locks (LockService) take a dedicated, "
                . 'non-pooled connection, because a session-scoped advisory lock on a PgBouncer transaction-mode '
                . 'connection leaks and loses mutual exclusion (architecture plan §4.12).'
            );
        }
        $dialect->configureOrm($config);
        self::$dialects[$scope] = $dialect;

        // DQL function and Doctrine type registration moved verbatim into $dialect->configureOrm() above
        // (§6 step 2 "pure move" of the dual-dialect architecture plan) — MariaDbDialect::configureOrm()
        // holds exactly what used to sit here.
        $config->setProxyNamespace(self::DEFAULT_NAMESPACE);
        $config->setAutoGenerateProxyClasses($configSettings['doctrineConfig']['proxyGenerationMode']);

        self::$instances[$scope] = DoctrineEntityManager::create($configSettings['connectionParams'], $config);
    }

    /**
     * The dialect of a scope (D10). Resolved and cached by create(); falls back to
     * {@see DialectResolver::forConnection()} for an entity manager that exists but was rebuilt outside create()
     * (2.70.9's rebuildOnSameConnection(), used when a closed EM must keep the caller's open transaction), and to
     * {@see DialectResolver::forDriver()} from the scope's configured driver when no instance exists yet at all
     * (DDL generation, golden tests — no connection required).
     */
    public static function getDialect(string $scope = self::SCOPE_DEFAULT): SqlDialect
    {
        if (isset(self::$dialects[$scope])) {
            return self::$dialects[$scope];
        }
        if (isset(self::$instances[$scope])) {
            return self::$dialects[$scope] = DialectResolver::forConnection(self::$instances[$scope]->getConnection());
        }
        $configSettings = self::getDBConfigForScope($scope);
        self::assertScopeIsConfigured($scope, $configSettings);
        return DialectResolver::forDriver($configSettings['connectionParams']['driver']);
    }

    /** Whether the scope has a connection configured (`DB_<SCOPE>_CONNECTION_DRIVER`) */
    public static function isScopeConfigured(string $scope = self::SCOPE_DEFAULT): bool
    {
        return (string)(self::getDBConfigForScope($scope)['connectionParams']['driver'] ?? '') !== '';
    }

    /**
     * An unconfigured scope fails with a message naming the variable, not with a type error deep in the dialect
     * resolution. Environments where the legacy tables live in the default database configure no LEGACY_DB scope.
     *
     * @param array<string, mixed> $configSettings As returned by getDBConfigForScope()
     * @throws DatabaseConfigurationException
     */
    protected static function assertScopeIsConfigured(string $scope, array $configSettings): void
    {
        if ((string)($configSettings['connectionParams']['driver'] ?? '') === '') {
            throw new DatabaseConfigurationException(sprintf(
                'The database scope %s is not configured: DB_%s_CONNECTION_DRIVER (and the other DB_%s_CONNECTION_* '
                . 'variables) are not set in this environment.',
                $scope, $scope, $scope
            ));
        }
    }

    /**
     * @return DoctrineQueryBuilder
     */
    public static function createQueryBuilder(string $scope = self::SCOPE_DEFAULT): DoctrineQueryBuilder
    {
        return self::getInstance(scope: $scope)->createQueryBuilder();
    }
}
