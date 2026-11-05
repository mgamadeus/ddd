<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use Predis\ClientInterface;
use Redis;
use RedisArray;
use RedisCluster;
use Relay\Relay;

/**
 * Shared implementation of the {@see Cache} atomic counter and lock operations for the Redis-backed caches
 * ({@see \DDD\Infrastructure\Cache\Redis} + {@see \DDD\Infrastructure\Cache\RedisSentinel}): they run over the NATIVE
 * Redis client, not the PSR-6 `RedisAdapter`, so each operation is one atomic server-side step (no lock of our own).
 * Each using class captures its native connection into {@see $nativeRedisConnection} inside `getCacheAdapter()`.
 * phpredis (`\Redis`, `RedisArray`, `RedisCluster`, Relay) and Predis take `SET` options and `EVAL` arguments in
 * different shapes; {@see setIfAbsentWithExpiry()} and {@see evaluateScript()} hide the difference.
 */
trait RedisAtomicCounterTrait
{
    /**
     * INCRBY, and EXPIRE only when this call created the counter — in one atomic step, so the TTL counts from the
     * creation (a later increment never extends it) and no process killed between two commands leaves a counter
     * without expiry. KEYS[1] counter, ARGV[1] increment, ARGV[2] TTL in seconds or '' for none.
     */
    protected const string INCREMENT_SCRIPT = <<<'LUA'
        local value = redis.call('incrby', KEYS[1], ARGV[1])
        if value == tonumber(ARGV[1]) and ARGV[2] ~= '' then
            redis.call('expire', KEYS[1], ARGV[2])
        end
        return value
        LUA;

    /** DEL only when the lock still carries this owner's token. KEYS[1] lock, ARGV[1] token. */
    protected const string RELEASE_LOCK_SCRIPT = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then
            return redis.call('del', KEYS[1])
        end
        return 0
        LUA;

    /** The native Redis client (captured by the using class in getCacheAdapter()), used for atomic commands. */
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
        return (int)$this->evaluateScript(
            self::INCREMENT_SCRIPT,
            [$this->counterKey($key)],
            [(string)$by, $ttl !== null ? (string)$ttl : '']
        );
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

    public function acquireLock(string $key, int $ttlSeconds): ?string
    {
        $token = $this->createLockToken($ttlSeconds);
        return $this->setIfAbsentWithExpiry($this->lockKey($key), $token, $ttlSeconds) ? $token : null;
    }

    public function releaseLock(string $key, string $token): bool
    {
        return (int)$this->evaluateScript(self::RELEASE_LOCK_SCRIPT, [$this->lockKey($key)], [$token]) === 1;
    }

    /** `SET <key> <value> NX EX <ttl>` — true when the key was absent and is now set. */
    protected function setIfAbsentWithExpiry(string $key, string $value, int $ttlSeconds): bool
    {
        $connection = $this->nativeRedisConnection();
        if ($connection instanceof ClientInterface) {
            // Predis: a Status "OK" on success, null when the key exists
            return $connection->set($key, $value, 'EX', $ttlSeconds, 'NX') !== null;
        }
        return $connection->set($key, $value, ['nx', 'ex' => $ttlSeconds]) === true;
    }

    /**
     * Runs a Lua script atomically on the server.
     *
     * @param string $script The script
     * @param string[] $keys Its KEYS
     * @param string[] $arguments Its ARGV
     * @return mixed The script's return value
     */
    protected function evaluateScript(string $script, array $keys, array $arguments): mixed
    {
        $connection = $this->nativeRedisConnection();
        if ($connection instanceof ClientInterface) {
            return $connection->eval($script, count($keys), ...$keys, ...$arguments);
        }
        return $connection->eval($script, [...$keys, ...$arguments], count($keys));
    }
}
