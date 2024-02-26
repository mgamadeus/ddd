<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use DDD\Infrastructure\Cache\Predis\Client;
use Symfony\Component\Cache\Adapter\RedisAdapter;

class RedisSentinel extends Cache
{
    private RedisAdapter $adapter;

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
            $client = new Client($this->config['sentinels'], $options);
            $this->adapter = new RedisAdapter($client, $this->config['namespace'], $this->ttl);
        }
        return $this->adapter;
    }
}