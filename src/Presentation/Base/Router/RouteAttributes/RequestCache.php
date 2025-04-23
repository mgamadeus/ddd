<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Router\RouteAttributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class RequestCache
{
    /**
     * @param int $ttl seconds to live
     * @param array<string> $headersToConsiderForCacheKey whitelist of allowed heaaders for caching
     */
    public function __construct(
        public int $ttl,
        public array $headersToConsiderForCacheKey = [],
        public bool $considerCurrentAuthAccountForCacheKey = false,
    ) {}
}
