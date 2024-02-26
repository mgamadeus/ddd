<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use Symfony\Component\Cache\Adapter\RedisAdapter;

class Redis extends Cache
{
    private RedisAdapter $adapter;

    public function getCacheAdapter(): RedisAdapter
    {
        if (!isset($this->adapter)) {
            $redisConnection = RedisAdapter::createConnection(
                sprintf(
                    'redis://%s@%s:%d/%d',
                    $this->config['password'],
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['database']
                )
            );

            $this->adapter = new RedisAdapter(
                $redisConnection,
                $this->config['namespace'],
                $this->ttl,
                $this->marshaller
            );
        }
        return $this->adapter;
    }
}
