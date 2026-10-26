<?php

declare(strict_types=1);

/**
 * Bootstrap of the integration suite (phpunit.integration.xml): the unit suite's minimal container plus the
 * connection of the throwaway MariaDB from tests/Integration/docker-compose.yml. Config::getEnv() reads $_ENV.
 */

require __DIR__ . '/../bootstrap.php';

if (!defined('APP_PREFIX')) {
    define('APP_PREFIX', 'ddd_it');
}
if (!defined('APP_ROOT_DIR')) {
    define('APP_ROOT_DIR', dirname(__DIR__, 2));
}

$integrationEnvironment = [
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
];
foreach ($integrationEnvironment as $environmentName => $environmentValue) {
    $_ENV[$environmentName] = $environmentValue;
}
