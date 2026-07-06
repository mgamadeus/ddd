<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * Consumes one token from the limiter described by a {@see QuotaLimiterSpec} for a given key. The seam between the
 * {@see QuotaGuard}'s pure decision logic and the concrete rate-limiter backend: the shipped adapter
 * ({@see SymfonyRateLimiterQuotaConsumer}) builds a Symfony `RateLimiterFactory` from the spec over the DDD Redis cache;
 * unit tests inject a fake, so the guard is testable without Redis or the rate-limiter component.
 */
interface QuotaConsumerInterface
{
    public function consume(QuotaLimiterSpec $limiterSpec, string $key): QuotaConsumeResult;
}
