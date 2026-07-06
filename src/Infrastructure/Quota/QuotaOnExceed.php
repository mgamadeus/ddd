<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * What a {@see QuotaDefinition} group does to a request that breaches it (ADR-019). Default is a standards-compliant
 * throttle; the legacy variant exists so pre-existing rate limits can be migrated 1:1 (they answered HTTP 400, and
 * clients may match on that) without becoming more permissive.
 */
final class QuotaOnExceed
{
    /** HTTP 429 Too Many Requests + a `Retry-After` header. */
    public const string THROTTLE_429 = 'throttle_429';

    /** HTTP 400 Bad Request with a custom message — behaviour parity with the legacy RateLimitTrait limiters. */
    public const string BAD_REQUEST_400 = 'bad_request_400';
}
