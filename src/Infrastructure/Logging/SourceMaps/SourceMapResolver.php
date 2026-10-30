<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\SourceMaps;

use DDD\Infrastructure\Services\SourceMapResolverService;

/**
 * @deprecated since 3.12.4 — use {@see SourceMapResolverService}, fetched from the container with
 *     `getService(SourceMapResolverService::class)`. This name lay outside the namespaces an application registers as
 *     public services, so fetching it from a compiled container failed. Kept for its constants and for code that
 *     constructs it directly.
 */
class SourceMapResolver extends SourceMapResolverService
{
}
