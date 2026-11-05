<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Cache;

use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Cache\Redis;

/**
 * Counters and locks over phpredis against the harness Redis (DDD_TEST_REDIS_HOST, DDD_TEST_REDIS_PORT,
 * DDD_TEST_REDIS_PASSWORD; skipped without them or without ext-redis).
 */
class RedisCacheCounterAndLockTest extends CacheCounterAndLockContractTestCase
{
    /** @return array<string, mixed> Cache config of the harness Redis */
    public static function redisConfig(): array
    {
        return [
            'host' => getenv('DDD_TEST_REDIS_HOST') ?: '',
            'port' => (int)(getenv('DDD_TEST_REDIS_PORT') ?: 6380),
            'password' => getenv('DDD_TEST_REDIS_PASSWORD') ?: 'ddd-test',
            'database' => 0,
            'namespace' => 'ddd-test',
            'defaultTTL' => 60,
        ];
    }

    protected function createCache(): Cache
    {
        if (!getenv('DDD_TEST_REDIS_HOST')) {
            self::markTestSkipped('DDD_TEST_REDIS_HOST is not set (start the harness redis service)');
        }
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is not loaded');
        }
        return new Redis(self::redisConfig());
    }
}
