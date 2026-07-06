<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * One limiter GROUP of a quota type: the {@see QuotaLimiterSpec}s to consume (multi-horizon — minute / hour / day) and
 * the ONE {@see QuotaKeyStrategy} they all count against. Pure data; a quota type can carry several groups
 * ({@see QuotaRegistry::forType()} returns a list — e.g. an account group + a shared IP group). Consuming ALL specs
 * enforces the whole multi-window quota; the FIRST that rejects denies.
 */
readonly class QuotaDefinition
{
    /**
     * @param QuotaLimiterSpec[] $limiterSpecs The limiters of this group, all consumed per request.
     * @param string $keyStrategy A {@see QuotaKeyStrategy} constant — what the count is keyed on.
     * @param string[] $keyFields For {@see QuotaKeyStrategy::BY_CUSTOM}: request-field paths whose values compose the
     *                            key (e.g. `['header.x-real-ip', 'body.installationId']`).
     * @param string $onExceed A {@see QuotaOnExceed} constant — how a breach is rejected (429 throttle vs legacy 400).
     * @param string|null $message The message for the rejection (legacy-parity custom text; null = a default).
     */
    public function __construct(
        public array $limiterSpecs,
        public string $keyStrategy,
        public array $keyFields = [],
        public string $onExceed = QuotaOnExceed::THROTTLE_429,
        public ?string $message = null,
    ) {
    }
}
