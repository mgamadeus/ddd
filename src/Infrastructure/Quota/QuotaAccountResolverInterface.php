<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the ACCOUNT a request's quota is keyed against ({@see QuotaKeyStrategy::BY_ACCOUNT}) — the consuming
 * application's extension point, since WHICH account owns the budget a request spends is app-domain knowledge. The
 * engine ({@see QuotaGuard} / {@see \DDD\Symfony\EventListeners\QuotaSubscriber}) depends only on this port; the app
 * binds a concrete resolver.
 *
 * Runs at `kernel.controller` — BEFORE the controller's arguments are resolved — so it must work from the request /
 * route params alone. Returns null when no account applies (unauthenticated); the BY_ACCOUNT group then fails open and
 * only the BY_IP group enforces.
 */
interface QuotaAccountResolverInterface
{
    public function resolveAccountId(Request $request): ?int;
}
