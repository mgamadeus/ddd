<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Cache;

use DDD\Infrastructure\Cache\Apc;
use DDD\Infrastructure\Cache\Cache;

/**
 * Counters and locks over APCu. Skipped unless APCu is enabled for the CLI and measures TTLs in real time:
 * `php -d apc.enable_cli=1 -d apc.use_request_time=0` (with the default request time an entry never expires within
 * one CLI run).
 */
class ApcCacheCounterAndLockTest extends CacheCounterAndLockContractTestCase
{
    protected function createCache(): Cache
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled() || ini_get('apc.use_request_time')) {
            self::markTestSkipped('APCu is not enabled for the CLI with real-time TTLs (php -d apc.enable_cli=1 -d apc.use_request_time=0)');
        }
        return new Apc(['namespace' => 'ddd-test', 'defaultTTL' => 60]);
    }
}
