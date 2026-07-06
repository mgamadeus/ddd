<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use Predis\ClientInterface;
use Redis;
use RedisArray;
use RedisCluster;
use Relay\Relay;

/**
 * Shared implementation of the {@see Cache} atomic counter operations for the Redis-backed caches ({@see \DDD\Infrastructure\Cache\Redis}
 * + {@see \DDD\Infrastructure\Cache\RedisSentinel}): they run over the NATIVE Redis client (`INCRBY` / `DECRBY` /
 * `EXPIRE`), not the PSR-6 `RedisAdapter`, so the counter is a single atomic server-side operation (no lock, one
 * round-trip). Each using class captures its native connection into {@see $nativeRedisConnection} inside
 * `getCacheAdapter()`. `incrBy` / `decrBy` / `expire` / `get` / `del` are present on phpredis `\Redis` and are routed
 * through Predis' `__call`, so the same code serves both drivers.
 */
trait RedisAtomicCounterTrait
{
    /** The native Redis client (captured by the using class in getCacheAdapter()), used for atomic counter commands. */
    protected Redis|RedisArray|RedisCluster|Relay|ClientInterface $nativeRedisConnection;

    protected function nativeRedisConnection(): Redis|RedisArray|RedisCluster|Relay|ClientInterface
    {
        if (!isset($this->nativeRedisConnection)) {
            $this->getCacheAdapter(); // lazily builds the adapter and, as a side effect, captures the connection
        }
        return $this->nativeRedisConnection;
    }

    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        $namespacedKey = $this->counterKey($key);
        $connection = $this->nativeRedisConnection();
        $newValue = (int)$connection->incrBy($namespacedKey, $by);
        if ($ttl !== null) {
            $connection->expire($namespacedKey, $ttl);
        }
        return $newValue;
    }

    public function decrement(string $key, int $by = 1): int
    {
        return (int)$this->nativeRedisConnection()->decrBy($this->counterKey($key), $by);
    }

    public function getCounter(string $key): int
    {
        $value = $this->nativeRedisConnection()->get($this->counterKey($key));
        return ($value === false || $value === null) ? 0 : (int)$value;
    }

    public function deleteCounter(string $key): void
    {
        $this->nativeRedisConnection()->del($this->counterKey($key));
    }
}
