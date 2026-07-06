<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use Symfony\Component\Cache\Adapter\RedisAdapter;

class Redis extends Cache
{
    use RedisAtomicCounterTrait;

    protected RedisAdapter $adapter;

    public function getCacheAdapter(): RedisAdapter
    {
        if (!isset($this->adapter)) {
            // Capture the native connection (kept in $nativeRedisConnection for atomic counter ops) instead of
            // discarding it — the PSR-6 RedisAdapter does not expose INCRBY/EXPIRE.
            $this->nativeRedisConnection = RedisAdapter::createConnection(
                sprintf(
                    'redis://%s@%s:%d/%d',
                    $this->config['password'],
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['database']
                )
            );

            $this->adapter = new RedisAdapter(
                $this->nativeRedisConnection,
                $this->config['namespace'],
                $this->ttl,
                $this->marshaller
            );
        }
        return $this->adapter;
    }
}
