<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Cache;

use DDD\Infrastructure\Cache\Redis;
use Predis\Client;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * The Redis cache over a plain Predis client instead of phpredis — the driver RedisSentinel uses — so the Predis
 * branches of the counter and lock operations run against a single harness Redis.
 */
class PredisBackedRedisCache extends Redis
{
    public function getCacheAdapter(): RedisAdapter
    {
        if (!isset($this->adapter)) {
            $this->nativeRedisConnection = new Client([
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'password' => $this->config['password'],
                'database' => $this->config['database'],
            ]);
            $this->adapter = new RedisAdapter($this->nativeRedisConnection, $this->config['namespace'], $this->ttl);
        }
        return $this->adapter;
    }
}
