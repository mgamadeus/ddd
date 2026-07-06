<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

use DDD\Infrastructure\Cache\Cache;

/**
 * A {@see QuotaConsumerInterface} that counts LOCALLY in APC on the hot path and reconciles with a cluster-wide store
 * (Redis / RedisSentinel, {@see QuotaLimiterSpec::$cacheGroup}) only PERIODICALLY — so the vast majority of requests
 * never touch Redis, yet a global view is maintained (ADR-019, approximate distributed rate limiting).
 *
 * Per `(spec.id, key, window-bucket)` in APC: one atomic counter `local` (this node's admits not yet pushed) and two
 * scalars `snapshot` (the last cluster total this node fetched) + `syncAt`. On each request the node increments `local`
 * and decides on `estimate = snapshot + local`. It reconciles — one `INCRBY` on the cluster store, which pushes the
 * local delta AND returns the fresh global total in one round-trip — when the estimate approaches the limit
 * ({@see self::SYNC_NEAR_RATIO}) OR the sync is due (every {@see QuotaLimiterSpec::$syncEverySeconds} /
 * {@see QuotaLimiterSpec::$syncEveryRequests}). A `delta = 0` sync still refreshes `snapshot`, so a node picks up other
 * nodes' increments even when idle.
 *
 * Guarantees: Redis reads ≈ requests / syncEveryRequests; global overshoot ≤ nodes × syncEveryRequests (tune the sync
 * knobs for the Redis-load ↔ accuracy trade-off). Staleness only ever makes `snapshot` LOWER than reality (the counter
 * grows within a window), so it errs toward admitting — never a false 429 from a stale local view.
 *
 * Concurrency: two processes on the SAME node reconciling the SAME key simultaneously can double-push their delta
 * (bounded, self-correcting each window, in the over-strict direction). A single-flight APC lock would remove it; not
 * added here because the approach is already approximate by design.
 */
class ApcRedisSyncingQuotaConsumer implements QuotaConsumerInterface
{
    /** Force a reconcile once the local estimate reaches this fraction of the limit (tighten enforcement near the cap). */
    protected const float SYNC_NEAR_RATIO = 0.8;

    public function consume(QuotaLimiterSpec $limiterSpec, string $key): QuotaConsumeResult
    {
        $window = max(1, $limiterSpec->windowSeconds);
        $now = $this->now();
        $bucket = intdiv($now, $window);
        $ttl = $window * 2;

        $base = 'qsync:' . $limiterSpec->id . ':' . $key . ':' . $bucket;
        $localKey = $base . ':local';
        $snapshotKey = $base . ':snapshot';
        $syncAtKey = $base . ':syncAt';
        $globalCounterKey = $base . ':global';

        $localCache = $this->localCache();

        // Hot path: count this attempt locally (atomic, no network).
        $localCache->increment($localKey, 1, $ttl);

        $snapshot = $this->asInt($localCache->get($snapshotKey));
        $local = max(0, $localCache->getCounter($localKey));
        $estimate = $snapshot + $local;

        $syncAt = $this->asInt($localCache->get($syncAtKey));
        $reconcileDue = ($now - $syncAt) >= $limiterSpec->syncEverySeconds
            || $local >= $limiterSpec->syncEveryRequests;
        $reconcileNear = $estimate >= (int)ceil($limiterSpec->limit * self::SYNC_NEAR_RATIO);

        if ($reconcileDue || $reconcileNear) {
            $globalCache = $this->globalCache($limiterSpec->cacheGroup);
            $delta = $localCache->getCounter($localKey);
            // One round-trip: push this node's delta AND read back the fresh cluster total.
            $newGlobal = $globalCache->increment($globalCounterKey, $delta, $ttl);
            if ($delta > 0) {
                $localCache->decrement($localKey, $delta); // clear only what we pushed (concurrent admits survive)
            }
            $localCache->set($snapshotKey, $newGlobal, $ttl);
            $localCache->set($syncAtKey, $now, $ttl);
            $estimate = $newGlobal + max(0, $localCache->getCounter($localKey));
        }

        if ($estimate > $limiterSpec->limit) {
            return QuotaConsumeResult::rejected($window - ($now % $window));
        }
        return QuotaConsumeResult::accepted();
    }

    /** The per-node local counter store (always APC — fast, in-process shared memory). Protected for test override. */
    protected function localCache(): Cache
    {
        return Cache::instance(Cache::CACHE_GROUP_APC);
    }

    /** The cluster-wide reconcile store (the spec's cache group — Redis / RedisSentinel). Protected for test override. */
    protected function globalCache(string $cacheGroup): Cache
    {
        return Cache::instance($cacheGroup);
    }

    /** Current unix time. Protected so tests can control the clock. */
    protected function now(): int
    {
        return time();
    }

    protected function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}
