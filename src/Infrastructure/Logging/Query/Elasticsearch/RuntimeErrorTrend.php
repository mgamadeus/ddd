<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The error trend: the `trend` date histogram's buckets, oldest first.
 *
 * @property RuntimeErrorTrendBucket[] $elements;
 * @method RuntimeErrorTrendBucket[] getElements()
 * @method RuntimeErrorTrendBucket|null first()
 */
class RuntimeErrorTrend extends ObjectSet
{
    /**
     * @param array<string, mixed> $aggregation The decoded aggregations (or a bucket) carrying `trend`
     */
    public static function buildFromDateHistogram(array $aggregation): static
    {
        $runtimeErrorTrend = new static();
        foreach ($aggregation['trend']['buckets'] ?? [] as $trendBucket) {
            $runtimeErrorTrendBucket = RuntimeErrorTrendBucket::buildFromBucket($trendBucket);
            $runtimeErrorTrend->add($runtimeErrorTrendBucket);
        }
        return $runtimeErrorTrend;
    }
}
