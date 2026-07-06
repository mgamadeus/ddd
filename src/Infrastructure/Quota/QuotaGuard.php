<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * The pure decision core of the quota framework: given a quota type + the request {@see QuotaContext}, consume every
 * limiter of every group the {@see QuotaRegistry} maps for the type and return the verdict. No HTTP, no reflection —
 * the {@see \DDD\Symfony\EventListeners\QuotaSubscriber} is the thin HTTP adapter over this, so the whole allow/deny
 * logic is unit-testable with a fake {@see QuotaConsumerInterface}.
 *
 * Semantics: the FIRST limiter that rejects denies the request (returns its `Retry-After`); a group whose key cannot be
 * resolved is SKIPPED (fail-open — a resolution gap must never block a legitimate request); all-accepted returns null.
 */
class QuotaGuard
{
    public function __construct(
        protected readonly QuotaRegistry $registry,
        protected readonly QuotaKeyResolver $keyResolver,
        protected readonly QuotaConsumerInterface $consumer,
    ) {
    }

    /**
     * @return QuotaDenial|null null = allowed; a {@see QuotaDenial} = DENIED (carries how to reject + Retry-After).
     */
    public function check(
        string $quotaType,
        QuotaContext $context,
        ?string $keyOverride = null,
        ?QuotaRequestFieldReaderInterface $fieldReader = null,
    ): ?QuotaDenial {
        foreach ($this->registry->forType($quotaType) as $group) {
            $key = $this->keyResolver->resolve(
                $group->keyStrategy,
                $context,
                $keyOverride,
                $group->keyFields,
                $fieldReader,
            );
            if ($key === null) {
                continue; // resolution gap → fail open for this group
            }
            foreach ($group->limiterSpecs as $limiterSpec) {
                $result = $this->consumer->consume($limiterSpec, $key);
                if (!$result->accepted) {
                    return new QuotaDenial($group->onExceed, $result->retryAfterSeconds, $group->message);
                }
            }
        }
        return null;
    }
}
