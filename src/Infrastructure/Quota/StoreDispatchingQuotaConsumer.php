<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * Routes each {@see QuotaLimiterSpec} to the consumer its {@see QuotaLimiterSpec::$store} selects, so a single config
 * can mix strategies per group (ADR-019): e.g. a per-account quota on the exact {@see SymfonyRateLimiterQuotaConsumer}
 * and a high-frequency IP flood guard on the {@see ApcRedisSyncingQuotaConsumer} (local APC + periodic Redis sync).
 * This is the {@see QuotaConsumerInterface} the {@see QuotaGuard} is given.
 */
class StoreDispatchingQuotaConsumer implements QuotaConsumerInterface
{
    public function __construct(
        protected readonly SymfonyRateLimiterQuotaConsumer $rateLimiterConsumer,
        protected readonly ApcRedisSyncingQuotaConsumer $apcRedisSyncingConsumer,
    ) {
    }

    public function consume(QuotaLimiterSpec $limiterSpec, string $key): QuotaConsumeResult
    {
        return match ($limiterSpec->store) {
            QuotaLimiterSpec::STORE_APC_REDIS_SYNC => $this->apcRedisSyncingConsumer->consume($limiterSpec, $key),
            default => $this->rateLimiterConsumer->consume($limiterSpec, $key),
        };
    }
}
