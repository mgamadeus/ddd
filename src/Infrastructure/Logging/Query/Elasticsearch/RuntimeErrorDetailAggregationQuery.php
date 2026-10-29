<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The DETAIL `_search` body of one runtime-error group: the group's filter pinned to its key, with last seen, a
 * gap-free per-group trend, the newest occurrences and — for COARSE — the per-route breakdown. A COARSE key is
 * `<error type>|<throw-site file>`; a part equal to the missing sentinel matches the documents without that field.
 * A COARSE key without exactly two parts degrades to the FINGERPRINT body.
 */
class RuntimeErrorDetailAggregationQuery extends ValueObject
{
    public const string MISSING_SENTINEL = RuntimeErrorAggregationQuery::MISSING_SENTINEL;

    public const int DEFAULT_SAMPLE_SIZE = 5;

    public const int MAX_SAMPLE_SIZE = 50;

    public const int ROUTE_BREAKDOWN_SIZE = 25;

    /** The group key: the fingerprint (FINGERPRINT) or `<type>|<file>` (COARSE) */
    public string $groupKey = '';

    public int $hours = RuntimeErrorAggregationQuery::DEFAULT_HOURS;

    /** @var string[] */
    public array $levels = RuntimeErrorAggregationQuery::DEFAULT_LEVELS;

    public ?string $fixedInterval = null;

    /** Newest occurrences returned (1 … {@see self::MAX_SAMPLE_SIZE}) */
    public int $sampleSize = self::DEFAULT_SAMPLE_SIZE;

    public string $groupingDimension = RuntimeErrorAggregationQuery::DEFAULT_DIMENSION;

    public ?string $workspace = null;

    public ElasticsearchFieldNames $fieldNames;

    /**
     * @param string[] $levels
     */
    public static function build(
        string $shortFingerprint,
        int $hours = RuntimeErrorAggregationQuery::DEFAULT_HOURS,
        array $levels = RuntimeErrorAggregationQuery::DEFAULT_LEVELS,
        ?string $fixedInterval = null,
        int $sampleSize = self::DEFAULT_SAMPLE_SIZE,
        string $groupingDimension = RuntimeErrorAggregationQuery::DEFAULT_DIMENSION,
        ?string $workspace = null,
        ?ElasticsearchFieldNames $fieldNames = null,
    ): static {
        $runtimeErrorDetailAggregationQuery = new static();
        $runtimeErrorDetailAggregationQuery->groupKey = $shortFingerprint;
        $runtimeErrorDetailAggregationQuery->hours = $hours;
        $runtimeErrorDetailAggregationQuery->levels = $levels;
        $runtimeErrorDetailAggregationQuery->fixedInterval = $fixedInterval;
        $runtimeErrorDetailAggregationQuery->sampleSize = min(max($sampleSize, 1), self::MAX_SAMPLE_SIZE);
        $runtimeErrorDetailAggregationQuery->groupingDimension = $groupingDimension;
        $runtimeErrorDetailAggregationQuery->workspace = $workspace;
        $runtimeErrorDetailAggregationQuery->fieldNames = $fieldNames ?? new ElasticsearchFieldNames();
        return $runtimeErrorDetailAggregationQuery;
    }

    /** @return array<string, mixed> */
    public function toDetailBody(): array
    {
        $innerAggregationQuery = RuntimeErrorAggregationQuery::build(
            $this->hours,
            $this->levels,
            RuntimeErrorAggregationQuery::DEFAULT_SIZE,
            $this->fixedInterval,
            groupingDimension: $this->groupingDimension,
            workspace: $this->workspace,
            fieldNames: $this->fieldNames,
        );
        $trendInterval = $innerAggregationQuery->resolveTrendInterval();
        if ($this->groupingDimension === RuntimeErrorAggregationQuery::DIMENSION_COARSE) {
            return $this->buildCoarseDetailBody($innerAggregationQuery, $trendInterval);
        }
        return $this->buildFingerprintDetailBody($innerAggregationQuery, $trendInterval);
    }

    /** @return array<string, mixed> */
    protected function buildFingerprintDetailBody(RuntimeErrorAggregationQuery $innerAggregationQuery, string $trendInterval): array
    {
        // FINGERPRINT or GROUP_HASH: one term field
        $fingerprintField = $innerAggregationQuery->getSingleTermGroupingField();
        $filterClauses = $innerAggregationQuery->buildFilterClauses();
        $filterClauses[] = ['term' => [$fingerprintField => $this->groupKey]];
        return [
            'size' => 0,
            'query' => ['bool' => ['filter' => $filterClauses]],
            'aggs' => [
                RuntimeErrorAggregationQuery::OUTER_AGG_KEY => [
                    'terms' => ['field' => $fingerprintField, 'size' => 1],
                    'aggs' => $this->buildDetailSubAggs($innerAggregationQuery, $trendInterval, false),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function buildCoarseDetailBody(RuntimeErrorAggregationQuery $innerAggregationQuery, string $trendInterval): array
    {
        $groupKeyParts = explode('|', $this->groupKey, 2);
        if (count($groupKeyParts) !== 2) {
            return $this->buildFingerprintDetailBody($innerAggregationQuery, $trendInterval);
        }
        $filterClauses = $innerAggregationQuery->buildFilterClauses();
        foreach ([$this->fieldNames->keyword('error.type'), $this->fieldNames->keyword('log.origin.file.name')] as $partIndex => $field) {
            // the sentinel is never stored: a term on it would match nothing
            $filterClauses[] = $groupKeyParts[$partIndex] === self::MISSING_SENTINEL
                ? ['bool' => ['must_not' => [['exists' => ['field' => $field]]]]]
                : ['term' => [$field => $groupKeyParts[$partIndex]]];
        }
        return [
            'size' => 0,
            'query' => ['bool' => ['filter' => $filterClauses]],
            'aggs' => [
                RuntimeErrorAggregationQuery::OUTER_AGG_KEY => [
                    'multi_terms' => [
                        'terms' => $innerAggregationQuery->buildCoarseTerms(),
                        'size' => 1,
                    ],
                    'aggs' => $this->buildDetailSubAggs($innerAggregationQuery, $trendInterval, true),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function buildDetailSubAggs(
        RuntimeErrorAggregationQuery $innerAggregationQuery,
        string $trendInterval,
        bool $includeRouteBreakdown
    ): array {
        $subAggs = $innerAggregationQuery->buildSharedGroupSubAggs();
        $subAggs['last_seen'] = ['max' => ['field' => '@timestamp', 'format' => 'strict_date_optional_time']];
        $subAggs['trend'] = [
            'date_histogram' => [
                'field' => '@timestamp',
                'fixed_interval' => $trendInterval,
                'min_doc_count' => 0,
                'extended_bounds' => ['min' => 'now-' . $this->hours . 'h', 'max' => 'now'],
            ],
        ];
        $subAggs['recent_occurrences'] = [
            'top_hits' => [
                'size' => $this->sampleSize,
                'sort' => [['@timestamp' => 'desc']],
                '_source' => $innerAggregationQuery->getSourceFields(),
            ],
        ];
        if ($includeRouteBreakdown) {
            // `missing` keeps route-less occurrences (console, messenger) in the breakdown
            $subAggs['by_route'] = [
                'terms' => [
                    'field' => $this->fieldNames->keyword('route_name'),
                    'size' => self::ROUTE_BREAKDOWN_SIZE,
                    'order' => ['_count' => 'desc'],
                    'missing' => self::MISSING_SENTINEL,
                ],
            ];
        }
        return $subAggs;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
