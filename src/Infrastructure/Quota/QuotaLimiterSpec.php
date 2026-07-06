<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * One concrete rate limiter derived from the `Common.Quota` config: a stable id (the storage namespace), the token
 * limit, the window interval, the windowing policy, and the DDD cache group its counter is stored in. {@see QuotaRegistry}
 * builds these from config; the {@see QuotaConsumerInterface} adapter turns each into a live limiter. The id is
 * `<quotaType>:<keyStrategy>:<horizon>` so every (type, subject-dimension, window) counts in its own namespace.
 *
 * {@see $cacheGroup} is a {@see \DDD\Infrastructure\Cache\Cache} group constant — configurable per group so a
 * high-frequency flood guard can run on the faster per-node APC while account quotas run on cluster-wide Redis.
 *
 * {@see $store} selects the counting strategy:
 *  - {@see self::STORE_RATE_LIMITER} — Symfony RateLimiter over the single {@see $cacheGroup} store (exact, one
 *    round-trip per request).
 *  - {@see self::STORE_APC_REDIS_SYNC} — count locally in APC and reconcile with the {@see $cacheGroup} store (Redis)
 *    every {@see $syncEverySeconds} / {@see $syncEveryRequests} (approximate cluster view, most requests never touch
 *    Redis). {@see $windowSeconds} is the fixed-window length used for bucketing.
 */
readonly class QuotaLimiterSpec
{
    public const string POLICY_SLIDING_WINDOW = 'sliding_window';
    public const string POLICY_FIXED_WINDOW = 'fixed_window';
    public const string POLICY_TOKEN_BUCKET = 'token_bucket';

    /** Symfony RateLimiter over one store (exact, per-request). */
    public const string STORE_RATE_LIMITER = 'rate_limiter';
    /** Local APC counter reconciled periodically with the cacheGroup store (approximate, few Redis hits). */
    public const string STORE_APC_REDIS_SYNC = 'apc_redis_sync';

    public function __construct(
        public string $id,
        public int $limit,
        public string $interval,
        public int $windowSeconds,
        public string $policy = self::POLICY_SLIDING_WINDOW,
        public string $cacheGroup = 'redis',
        public string $store = self::STORE_RATE_LIMITER,
        public int $syncEverySeconds = 5,
        public int $syncEveryRequests = 50,
    ) {
    }
}
