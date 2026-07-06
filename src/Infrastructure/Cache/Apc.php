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
}