<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The groups of the GROUPS aggregation, ranked by count.
 *
 * @property RuntimeErrorGroup[] $elements;
 * @method RuntimeErrorGroup[] getElements()
 * @method RuntimeErrorGroup|null first()
 */
class RuntimeErrorGroups extends ObjectSet
{
    /**
     * @param array<string, mixed> $aggregation The decoded `aggregations` of the response
     */
    public static function buildFromAggregation(array $aggregation, ?ElasticsearchFieldNames $fieldNames = null): static
    {
        $runtimeErrorGroups = new static();
        $fieldRedactor = new RuntimeErrorFieldRedactor();
        foreach ($aggregation[RuntimeErrorAggregationQuery::OUTER_AGG_KEY]['buckets'] ?? [] as $bucket) {
            // bucket keys are unique: content-keyed, in ranking order
            $runtimeErrorGroup = RuntimeErrorGroup::buildFromBucket($bucket, $fieldRedactor, $fieldNames);
            $runtimeErrorGroups->add($runtimeErrorGroup);
        }
        return $runtimeErrorGroups;
    }
}
