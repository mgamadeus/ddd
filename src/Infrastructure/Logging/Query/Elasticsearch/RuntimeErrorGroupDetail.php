<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One group's detail: the group, last seen, its trend (gap-free over the window), the newest occurrences and the
 * per-route breakdown (COARSE). A key absent in the window gives an empty detail, not an error.
 */
class RuntimeErrorGroupDetail extends ValueObject
{
    public RuntimeErrorGroup $group;

    public ?string $lastSeenTimestamp = null;

    public RuntimeErrorTrend $perGroupTrend;

    public RuntimeErrorOccurrences $recentOccurrences;

    public RuntimeErrorRouteCounts $routeBreakdown;

    /**
     * @param array<string, mixed> $aggregation The decoded `aggregations` of the DETAIL response
     */
    public static function buildFromAggregation(
        array $aggregation,
        RuntimeErrorFieldRedactor $fieldRedactor,
        ?ElasticsearchFieldNames $fieldNames = null
    ): static {
        $runtimeErrorGroupDetail = new static();
        $groupBucket = $aggregation[RuntimeErrorAggregationQuery::OUTER_AGG_KEY]['buckets'][0] ?? [];
        $runtimeErrorGroupDetail->group = RuntimeErrorGroup::buildFromBucket($groupBucket, $fieldRedactor, $fieldNames);
        $lastSeenTimestamp = $groupBucket['last_seen']['value_as_string'] ?? null;
        $runtimeErrorGroupDetail->lastSeenTimestamp = $lastSeenTimestamp !== null ? (string)$lastSeenTimestamp : null;
        $runtimeErrorGroupDetail->perGroupTrend = RuntimeErrorTrend::buildFromDateHistogram($groupBucket);
        $runtimeErrorGroupDetail->recentOccurrences = RuntimeErrorOccurrences::buildFromTopHits(
            $groupBucket['recent_occurrences'] ?? [],
            $fieldRedactor
        );
        $runtimeErrorGroupDetail->routeBreakdown = RuntimeErrorRouteCounts::buildFromBuckets(
            $groupBucket['by_route']['buckets'] ?? [],
            $fieldRedactor
        );
        return $runtimeErrorGroupDetail;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
