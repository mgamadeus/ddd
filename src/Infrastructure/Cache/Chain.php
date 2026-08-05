<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use BadMethodCallException;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Throwable;

/**
 * TIERED cache — a cache group whose adapter is a Symfony ChainAdapter over the adapters of OTHER configured
 * cache groups, in order. Reads try the tiers front to back and BACKFILL earlier tiers on a later-tier hit
 * (with the item's remaining lifetime); writes go to every tier (ChainAdapter semantics).
 *
 * WHY: APCu is per-machine (and per-process in CLI) — every FPM reload, worker restart and every machine
 * recomputes each EntityCache entry from the database, and local CLI never gets a warm cache at all. A chain
 * `apc,redis` computes once per CLUSTER (Redis backfills APCu after a restart) while the hot read stays
 * APCu-fast; a local chain of just `phpfiles` gives CLI processes a shared, process-surviving cache. Entity
 * scope invalidation (EntityCacheScopeInvalidations) is validated ABOVE the cache instance at read time and is
 * therefore tier-agnostic — no invalidation semantics change.
 *
 * CONFIG (env, per group): route any group to the chain type and list its tiers, e.g.
 *   CACHE_APC_CACHE_GROUP='chain'
 *   CACHE_APC_TIERS='apc,redis'        # server: in-memory first, cluster-shared second
 *   CACHE_APC_TIERS='phpfiles'         # local dev/CLI: file cache only
 * Every tier name must be a configured cache group ({@see Cache::instance()}). The tier list is the
 * degradation mechanism: an environment without Redis simply does not list it. A tier whose ADAPTER cannot be
 * constructed (missing extension, invalid config) is skipped with a PHP warning — but a tier that constructs
 * and fails at RUNTIME (e.g. Redis going down mid-operation) throws exactly as it would as a standalone group.
 *
 * Atomic counters delegate to the FIRST tier whose backend supports them (APCu/Redis) — counters are native
 * single-backend operations and are never chained.
 */
class Chain extends Cache
{
    protected ChainAdapter $adapter;

    /** @var Cache[] The resolved tier cache instances, in configured order. */
    protected array $tierCacheInstances = [];

    public function getCacheAdapter(): ChainAdapter
    {
        if (!isset($this->adapter)) {
            $tierAdapters = [];
            foreach ($this->getTierCacheInstances() as $tierCacheInstance) {
                try {
                    $tierAdapters[] = $tierCacheInstance->getCacheAdapter();
                } catch (Throwable $tierAdapterConstructionError) {
                    // Constructible-tier tolerance: a tier whose adapter cannot even be BUILT is skipped so the
                    // remaining tiers keep working (e.g. apcu extension absent). Runtime failures still throw.
                    trigger_error(
                        'Cache chain tier skipped (adapter construction failed): '
                        . $tierCacheInstance::class . ' — ' . $tierAdapterConstructionError->getMessage(),
                        E_USER_WARNING
                    );
                }
            }
            if ($tierAdapters === []) {
                throw new BadMethodCallException(
                    'Cache chain has no usable tiers — check the CACHE_<GROUP>_TIERS configuration.'
                );
            }
            $this->adapter = new ChainAdapter($tierAdapters);
        }
        return $this->adapter;
    }

    /**
     * The tier instances from the `tiers` config (comma-separated TIER TYPE names: apc|redis|phpfiles|
     * redisSentinel). Tiers are constructed DIRECTLY from their type + their group's env config — deliberately
     * NOT through {@see Cache::instance()}: the chained group's own name (e.g. `apc` routed to chain) may appear
     * as a tier, and resolving it through the group registry would return the chain itself (the instance
     * registry is keyed by group name across all classes) — the direct construction reads CACHE_APC_* for the
     * apc tier while the group stays chained. A chain tier inside a chain is refused (one level, no recursion).
     *
     * @return Cache[]
     */
    protected function getTierCacheInstances(): array
    {
        if ($this->tierCacheInstances === []) {
            $tierClassesByTypeName = [
                self::CACHE_TYPE_APC => Apc::class,
                self::CACHE_TYPE_REDIS => Redis::class,
                self::CACHE_TYPE_PHPFILES => PhpFiles::class,
                self::CACHE_TYPE_REDIS_SENTINEL => RedisSentinel::class,
            ];
            $tierTypeNames = $this->config['tiers'] ?? '';
            $tierTypeNames = is_array($tierTypeNames) ? $tierTypeNames : explode(',', (string)$tierTypeNames);
            foreach ($tierTypeNames as $tierTypeName) {
                $tierTypeName = trim($tierTypeName);
                $tierClass = $tierClassesByTypeName[$tierTypeName] ?? null;
                if ($tierClass === null) {
                    if ($tierTypeName !== '') {
                        trigger_error("Cache chain tier '$tierTypeName' is not a chainable cache type — skipped.", E_USER_WARNING);
                    }
                    continue;
                }
                $tierConfig = Cache::getCacheConfig($tierTypeName);
                $this->tierCacheInstances[] = new $tierClass($tierConfig);
            }
        }
        return $this->tierCacheInstances;
    }

    // region Atomic counter operations — delegated to the first counter-capable tier (never chained)

    protected function getCounterTier(): Cache
    {
        foreach ($this->getTierCacheInstances() as $tierCacheInstance) {
            if ($tierCacheInstance instanceof Apc
                || $tierCacheInstance instanceof Redis
                || $tierCacheInstance instanceof RedisSentinel) {
                return $tierCacheInstance;
            }
        }
        throw new BadMethodCallException('No tier of this cache chain supports atomic counter operations.');
    }

    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        return $this->getCounterTier()->increment($key, $by, $ttl);
    }

    public function decrement(string $key, int $by = 1): int
    {
        return $this->getCounterTier()->decrement($key, $by);
    }

    public function getCounter(string $key): int
    {
        return $this->getCounterTier()->getCounter($key);
    }

    public function deleteCounter(string $key): void
    {
        $this->getCounterTier()->deleteCounter($key);
    }

    // endregion
}
