<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

use Symfony\Component\HttpFoundation\Request;

/**
 * The framework DEFAULT {@see QuotaAccountResolverInterface}: resolves NO account (always null). Bound automatically by
 * {@see \DDD\Symfony\CompilerPasses\QuotaDefaultBindingsCompilerPass} when the consuming app has not bound its own
 * resolver, so the quota engine stays inert-by-default — an app that does not use account-keyed quotas (or does not use
 * quotas at all) still compiles and boots without wiring anything. With this resolver, {@see QuotaKeyStrategy::BY_ACCOUNT}
 * / {@see QuotaKeyStrategy::BY_ACCOUNT_LOCATION} groups fail open (null key → skipped by {@see QuotaGuard}); IP / custom
 * quotas still enforce. A consuming app that needs account quotas binds its own resolver over this default.
 */
class NullQuotaAccountResolver implements QuotaAccountResolverInterface
{
    public function resolveAccountId(Request $request): ?int
    {
        return null;
    }
}
