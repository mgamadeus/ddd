<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use RuntimeException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;

class Apc extends Cache
{
    protected ApcuAdapter $adapter;

    public function getCacheAdapter(): ApcuAdapter
    {
        if (!isset($this->adapter)) {
            $this->adapter = new ApcuAdapter(
                $this->config['namespace'] ?? '',
                $this->ttl,
                null
            );
        }
        return $this->adapter;
    }

    // region Atomic counter operations — native APCu (atomic, lock-free, in-process shared memory)

    /** `apcu_inc()` applies the TTL only when it creates the entry; a later increment keeps the original expiry. */
    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        $namespacedKey = $this->counterKey($key);
        $newValue = apcu_inc($namespacedKey, $by, $success, $ttl ?? $this->ttl);
        if ($newValue === false || $success === false) {
            throw new RuntimeException("APCu increment failed for counter '{$namespacedKey}'.");
        }
        return $newValue;
    }

    public function decrement(string $key, int $by = 1): int
    {
        $namespacedKey = $this->counterKey($key);
        $newValue = apcu_dec($namespacedKey, $by, $success);
        if ($newValue === false || $success === false) {
            throw new RuntimeException("APCu decrement failed for counter '{$namespacedKey}'.");
        }
        return $newValue;
    }

    public function getCounter(string $key): int
    {
        $value = apcu_fetch($this->counterKey($key), $success);
        return $success ? (int)$value : 0;
    }

    public function deleteCounter(string $key): void
    {
        apcu_delete($this->counterKey($key));
    }

    // endregion

    // region Locks — APCu shared memory: ONE host only (each host has its own APCu), not across servers

    public function acquireLock(string $key, int $ttlSeconds): ?string
    {
        $token = $this->createLockToken($ttlSeconds);
        return apcu_add($this->lockKey($key), $token, $ttlSeconds) ? $token : null;
    }

    /**
     * Compares the token, then deletes. APCu has no compare-and-delete for strings, so the two steps are not one
     * atomic operation: another process could take the lock between them only if it expired in that instant.
     */
    public function releaseLock(string $key, string $token): bool
    {
        $lockKey = $this->lockKey($key);
        $currentToken = apcu_fetch($lockKey, $success);
        if (!$success || $currentToken !== $token) {
            return false;
        }
        return apcu_delete($lockKey);
    }

    // endregion
}