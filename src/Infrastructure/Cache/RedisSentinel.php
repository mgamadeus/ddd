<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use DDD\Infrastructure\Cache\Predis\Client;
use Symfony\Component\Cache\Adapter\RedisAdapter;

class RedisSentinel extends Cache
{
    use RedisAtomicCounterTrait;

    protected RedisAdapter $adapter;

    public function getCacheAdapter(): RedisAdapter
    {
        if (!isset($this->adapter)) {
            $options = [
                'replication' => 'redis-sentinel',
                'service' => 'mymaster',
                'parameters' => [
                    'database' => $this->config['database'],
                    'password' => $this->config['password']
                ],
                'ttl' => $this->ttl,
            ];
            // Keep the Predis client for atomic counter ops (INCRBY/EXPIRE) — the PSR-6 adapter does not expose them.
            $this->nativeRedisConnection = new Client($this->config['sentinels'], $options);
            $this->adapter = new RedisAdapter($this->nativeRedisConnection, $this->config['namespace'], $this->ttl);
        }
        return $this->adapter;
    }
}