<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

use DDD\Infrastructure\Cache\Cache;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * The shipped {@see QuotaConsumerInterface}: builds a Symfony `RateLimiterFactory` from a {@see QuotaLimiterSpec} and
 * consumes one token for the given key, storing the counter in the DDD {@see Cache} group the spec names. This is the
 * ONE class that touches the Symfony RateLimiter component; {@see QuotaGuard} + its tests stay backend-agnostic.
 *
 * The storage is the DDD cache's underlying Symfony adapter ({@see Cache::getCacheAdapter()}, a PSR-6 pool) — so a group
 * configured `redis` is cluster-wide, `apc` is the faster per-node store, etc., WITHOUT any Symfony `cache.yaml`
 * change. One `CacheStorage` is memoized per cache group. A `LockFactory` may be injected for atomic concurrent
 * consumption; without it the limiter is best-effort (acceptable for abuse guards).
 */
class SymfonyRateLimiterQuotaConsumer implements QuotaConsumerInterface
{
    /** @var array<string, CacheStorage> one storage per DDD cache group, memoized */
    protected array $storagePerCacheGroup = [];

    public function __construct(
        protected readonly ?LockFactory $lockFactory = null,
    ) {
    }

    public function consume(QuotaLimiterSpec $limiterSpec, string $key): QuotaConsumeResult
    {
        $factory = new RateLimiterFactory(
            [
                'id' => $limiterSpec->id,
                'policy' => $limiterSpec->policy,
                'limit' => $limiterSpec->limit,
                'interval' => $limiterSpec->interval,
            ],
            $this->storageForCacheGroup($limiterSpec->cacheGroup),
            $this->lockFactory,
        );

        $rateLimit = $factory->create($key)->consume();
        if ($rateLimit->isAccepted()) {
            return QuotaConsumeResult::accepted();
        }

        $retryAfterSeconds = $rateLimit->getRetryAfter()->getTimestamp() - time();
        return QuotaConsumeResult::rejected($retryAfterSeconds);
    }

    protected function storageForCacheGroup(string $cacheGroup): CacheStorage
    {
        return $this->storagePerCacheGroup[$cacheGroup] ??= new CacheStorage(
            Cache::instance($cacheGroup)->getCacheAdapter()
        );
    }
}
