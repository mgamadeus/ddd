<?php

declare(strict_types=1);

/**
 * Bootstrap of the integration suite (phpunit.integration.xml): the unit suite's minimal container plus the
 * connection of the throwaway database from tests/Integration/docker-compose.yml. Config::getEnv() reads $_ENV.
 *
 * DDD_TEST_DRIVER selects the engine: pdo_mysql (default assumed by IntegrationTestCase::isIntegrationDatabaseEnabled()
 * when unset — every test skips) or pdo_pgsql. §6 step 1 of the dual-dialect architecture plan.
 */

require __DIR__ . '/../bootstrap.php';

if (!defined('APP_PREFIX')) {
    define('APP_PREFIX', 'ddd_it');
}
if (!defined('APP_ROOT_DIR')) {
    define('APP_ROOT_DIR', dirname(__DIR__, 2));
}

$driver = getenv('DDD_TEST_DRIVER') ?: ($_SERVER['DDD_TEST_DRIVER'] ?? 'pdo_mysql');

$integrationEnvironment = match ($driver) {
    'pdo_pgsql' => [
        'DB_DEFAULT_CONNECTION_DRIVER' => 'pdo_pgsql',
        'DB_DEFAULT_CONNECTION_HOST' => '127.0.0.1',
        'DB_DEFAULT_CONNECTION_HOST_PORT' => (string)($_SERVER['DDD_TEST_PG_PORT'] ?? getenv('DDD_TEST_PG_PORT') ?: '5433'),
        'DB_DEFAULT_CONNECTION_USER' => 'postgres',
        'DB_DEFAULT_CONNECTION_PASSWORD' => 'ddd-test',
        'DB_DEFAULT_CONNECTION_DB_NAME' => 'ddd_test',
        // The apps' production value (D4.4: PostgresDialect::connectionParams() maps this to `serverVersion`).
        'DB_DEFAULT_CONNECTION_DB_SERVER_VERSION' => '18.0',
        // Deliberately NO charset env var here: PostgresDialect::connectionParams() unsets it regardless (D4.4,
        // "charset" is always passed; DBAL's pgsql driver sends `SET NAMES` for ANY isset charset, "" included) —
        // the harness exercises the real refusal path instead of avoiding it by never setting the key.
        'DB_DEFAULT_DOCTRINE_PROXY_GENERATION_MODE' => 'AUTOGENERATE_EVAL',
        'DATABASE_TABLE_PREFIX' => '',
        'CACHE_APC_TTL' => '60',
        'CACHE_APC_NAMESPACE' => 'ddd_it',
        // §4.12 (locks, D3): PostgresLockService refuses to run without a direct, non-pooled connection — the
        // harness has no PgBouncer in front of its throwaway PostgreSQL (T3 pooler is its own compose, not this
        // one), so the "direct" host/port here are simply the same single-node server the pooled connection
        // above already uses. DDD_TEST_PG_DIRECT_PORT defaults to the same value as DDD_TEST_PG_PORT so a lock
        // test needs no extra env when the two happen to coincide, but can still be pointed elsewhere.
        'DB_DEFAULT_DIRECT_HOST' => '127.0.0.1',
        'DB_DEFAULT_DIRECT_PORT' => (string)($_SERVER['DDD_TEST_PG_DIRECT_PORT'] ?? getenv('DDD_TEST_PG_DIRECT_PORT') ?: ($_SERVER['DDD_TEST_PG_PORT'] ?? getenv('DDD_TEST_PG_PORT') ?: '5433')),
    ],
    default => [
        'DB_DEFAULT_CONNECTION_DRIVER' => 'pdo_mysql',
        'DB_DEFAULT_CONNECTION_HOST' => '127.0.0.1',
        'DB_DEFAULT_CONNECTION_HOST_PORT' => (string)($_SERVER['DDD_TEST_DB_PORT'] ?? getenv('DDD_TEST_DB_PORT') ?: '3317'),
        'DB_DEFAULT_CONNECTION_USER' => 'root',
        'DB_DEFAULT_CONNECTION_PASSWORD' => 'ddd-test',
        'DB_DEFAULT_CONNECTION_DB_NAME' => 'ddd_test',
        // the apps' value: DBAL resolves it to the same platform as production
        'DB_DEFAULT_CONNECTION_DB_SERVER_VERSION' => '10.6',
        'DB_DEFAULT_CONNECTION_DB_SERVER_CHARSET' => 'utf8mb4',
        'DB_DEFAULT_DOCTRINE_PROXY_GENERATION_MODE' => 'AUTOGENERATE_EVAL',
        'DATABASE_TABLE_PREFIX' => '',
        'CACHE_APC_TTL' => '60',
        'CACHE_APC_NAMESPACE' => 'ddd_it',
    ],
};
foreach ($integrationEnvironment as $environmentName => $environmentValue) {
    $_ENV[$environmentName] = $environmentValue;
}
