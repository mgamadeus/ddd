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
    /** The shared group fields, assembled by delegation to {@see RuntimeErrorGroup::buildFromBucket()}. */
    public RuntimeErrorGroup $group;

    /**
     * The timestamp of the LAST occurrence in the window — the `last_seen` max-agg `value_as_string`,
     * ISO-8601 as ES returns it. Null when the `last_seen` sub-agg is absent (empty detail path).
     */
    public ?string $lastSeenTimestamp = null;

    /** The per-group trend series for the window, built from the `trend` date-histogram sub-agg. */
    public RuntimeErrorTrend $perGroupTrend;

    /** The recent occurrences sample, built from the `recent_occurrences` top-hits sub-agg. */
    public RuntimeErrorOccurrences $recentOccurrences;

    /**
     * The per-route breakdown of occurrences in this group, from the `by_route` terms sub-agg (COARSE); an empty
     * set under FINGERPRINT and GROUP_HASH, which request no `by_route` sub-agg. Always initialised.
     */
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
            $fieldRedactor,
            $fieldNames
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
