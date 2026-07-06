<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * The per-request inputs {@see QuotaKeyResolver} turns into a limiter key: the resolved account, location, the effective
 * client IP, and any consuming-app-supplied custom key. Built once per request by the {@see QuotaSubscriber}. All
 * nullable — a strategy that needs a missing field yields a null key, and the {@see QuotaGuard} then fails OPEN for that
 * group (a resolution gap must never block a legitimate request).
 */
readonly class QuotaContext
{
    public function __construct(
        public ?int $accountId = null,
        public ?int $locationId = null,
        public ?string $clientIp = null,
        public ?string $customKey = null,
    ) {
    }
}
