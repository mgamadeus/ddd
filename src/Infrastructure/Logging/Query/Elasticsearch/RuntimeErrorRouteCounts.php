<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The per-route breakdown of a COARSE group, by count; empty for FINGERPRINT.
 *
 * @property RuntimeErrorRouteCount[] $elements;
 * @method RuntimeErrorRouteCount[] getElements()
 * @method RuntimeErrorRouteCount|null first()
 */
class RuntimeErrorRouteCounts extends ObjectSet
{
    /**
     * @param array<int, array<string, mixed>> $byRouteBuckets The decoded `by_route` buckets
     */
    public static function buildFromBuckets(array $byRouteBuckets, RuntimeErrorFieldRedactor $fieldRedactor): static
    {
        $runtimeErrorRouteCounts = new static();
        foreach ($byRouteBuckets as $routeBucket) {
            $runtimeErrorRouteCount = RuntimeErrorRouteCount::buildFromBucket($routeBucket, $fieldRedactor);
            $runtimeErrorRouteCounts->add($runtimeErrorRouteCount);
        }
        return $runtimeErrorRouteCounts;
    }
}
