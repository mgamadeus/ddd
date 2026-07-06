<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * The outcome of consuming ONE limiter for a request: whether the token was granted and, if not, how many seconds until
 * the window frees a slot (for the HTTP `Retry-After` header). A framework-agnostic wrapper over the concrete
 * rate-limiter result, so {@see QuotaGuard} + its tests never depend on the rate-limiter backend directly.
 */
readonly class QuotaConsumeResult
{
    public function __construct(
        public bool $accepted,
        public int $retryAfterSeconds = 0,
    ) {
    }

    public static function accepted(): self
    {
        return new self(true);
    }

    public static function rejected(int $retryAfterSeconds): self
    {
        return new self(false, max(1, $retryAfterSeconds));
    }
}
