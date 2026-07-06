<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * Reads a single request value by a dotted path, for composing a {@see QuotaKeyStrategy::BY_CUSTOM} key from request
 * data (ADR-019). Paths: `ip` (the effective client IP), `header.<name>`, `query.<name>`, `body.<name>`. The
 * {@see \DDD\Symfony\EventListeners\QuotaSubscriber} provides an implementation over the HTTP request; tests inject a
 * fake so {@see QuotaKeyResolver} stays pure + unit-testable. Returns null when the field is absent.
 */
interface QuotaRequestFieldReaderInterface
{
    public function fieldValue(string $path): ?string;
}
