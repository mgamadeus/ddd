<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * WHAT a quota is counted against — the subject a {@see QuotaDefinition} group keys on. The strategy is what makes one
 * category per-account and another per-IP: you key on the RESOURCE you protect. Generic — a consuming application picks
 * a strategy per quota group in its `Common.Quota` config; the framework resolves the concrete key via
 * {@see QuotaKeyResolver}.
 */
final class QuotaKeyStrategy
{
    /** Count per client IP — pre-auth flood / DoS guard (an anonymous attacker has no account yet). */
    public const string BY_IP = 'ip';

    /** Count per ACCOUNT — the authenticated (or resolved budget-owner) account. */
    public const string BY_ACCOUNT = 'account';

    /** Count per LOCATION. */
    public const string BY_LOCATION = 'location';

    /** Count per (account, location) — a finer intra-account fairness key. */
    public const string BY_ACCOUNT_LOCATION = 'account_location';

    /** Count against a composite key the consuming app supplies (a request field, a context id, …). */
    public const string BY_CUSTOM = 'custom';
}
