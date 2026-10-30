<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The newest occurrences of a group, newest first.
 *
 * @property RuntimeErrorOccurrence[] $elements;
 * @method RuntimeErrorOccurrence[] getElements()
 * @method RuntimeErrorOccurrence|null first()
 */
class RuntimeErrorOccurrences extends ObjectSet
{
    /**
     * @param array<string, mixed> $topHits The decoded `top_hits` aggregation
     */
    public static function buildFromTopHits(
        array $topHits,
        RuntimeErrorFieldRedactor $fieldRedactor,
        ?ElasticsearchFieldNames $fieldNames = null
    ): static
    {
        $runtimeErrorOccurrences = new static();
        foreach ($topHits['hits']['hits'] ?? [] as $hit) {
            $runtimeErrorOccurrence = RuntimeErrorOccurrence::buildFromHit($hit, $fieldRedactor, $fieldNames);
            $runtimeErrorOccurrences->add($runtimeErrorOccurrence);
        }
        return $runtimeErrorOccurrences;
    }
}
