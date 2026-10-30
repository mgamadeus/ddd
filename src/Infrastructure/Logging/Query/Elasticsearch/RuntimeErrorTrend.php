<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;

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

    /**
     * The runtime-error trend shape (`{timestamp, count}` buckets) of a time series, e.g. an issues trend — for
     * callers that still speak it.
     */
    public static function fromDateTimeValueSequence(DateTimeValueSequence $dateTimeValueSequence): static
    {
        $runtimeErrorTrend = new static();
        foreach ($dateTimeValueSequence->getElements() as $dateTimeValue) {
            $runtimeErrorTrendBucket = RuntimeErrorTrendBucket::buildFromBucket([
                'key_as_string' => gmdate('Y-m-d\TH:i:s.000\Z', $dateTimeValue->x->getTimestamp()),
                'doc_count' => (int)$dateTimeValue->y,
            ]);
            $runtimeErrorTrend->add($runtimeErrorTrendBucket);
        }
        return $runtimeErrorTrend;
    }
}
