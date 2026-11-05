<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Cache;

use DDD\Infrastructure\Cache\Cache;

/** Counters and locks over Predis against the harness Redis (see {@see RedisCacheCounterAndLockTest}). */
class PredisCacheCounterAndLockTest extends CacheCounterAndLockContractTestCase
{
    protected function createCache(): Cache
    {
        if (!getenv('DDD_TEST_REDIS_HOST')) {
            self::markTestSkipped('DDD_TEST_REDIS_HOST is not set (start the harness redis service)');
        }
        return new PredisBackedRedisCache(RedisCacheCounterAndLockTest::redisConfig());
    }
}
