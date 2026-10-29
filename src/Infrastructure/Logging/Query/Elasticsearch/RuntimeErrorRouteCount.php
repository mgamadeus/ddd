<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Occurrences of a COARSE group through one route (`—` = no route: console, messenger).
 */
class RuntimeErrorRouteCount extends ValueObject
{
    /** The route name from `route_name.keyword`, redacted; `"—"` for route-less occurrences. */
    public ?string $route = null;

    /** How many occurrences of this group surfaced on this route in the window (`doc_count`). */
    public int $count = 0;

    /**
     * @param array<string, mixed> $bucket One decoded `by_route` bucket
     */
    public static function buildFromBucket(array $bucket, RuntimeErrorFieldRedactor $fieldRedactor): static
    {
        $runtimeErrorRouteCount = new static();
        $runtimeErrorRouteCount->route = isset($bucket['key']) ? $fieldRedactor->redact((string)$bucket['key']) : null;
        $runtimeErrorRouteCount->count = (int)($bucket['doc_count'] ?? 0);
        return $runtimeErrorRouteCount;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->route ?? (string)spl_object_id($this));
    }
}
