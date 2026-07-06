<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * The verdict when a request breaches a quota (ADR-019): HOW to reject ({@see QuotaOnExceed}), the `Retry-After`
 * seconds, and an optional human message. Returned by {@see QuotaGuard::check()} (null = allowed); the
 * {@see \DDD\Symfony\EventListeners\QuotaSubscriber} turns it into the right HTTP exception.
 */
readonly class QuotaDenial
{
    public function __construct(
        public string $onExceed,
        public int $retryAfterSeconds,
        public ?string $message = null,
    ) {
    }
}
