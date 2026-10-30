<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Logging\Query\Elasticsearch\ElasticsearchIssuesQueryBackend;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorAggregationQuery;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorDetailAggregationQuery;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorFieldRedactor;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorGroupDetail;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorGroups;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorTrend;
use DDD\Infrastructure\Services\Service;

/**
 * Runtime errors straight from an Elasticsearch / OpenSearch log index, grouped in Elasticsearch: the groups, the
 * overall trend and one group's detail — the read side of a runtime-error dashboard and of
 * `app:runtime-errors:extract`. Independent of the issues registry (no tables); configured by the same
 * `ISSUES_QUERY_*` variables with the `ES_LOGS_*` fallback, whatever `ISSUES_QUERY_BACKEND` says.
 */
class RuntimeErrorsService extends Service
{
    protected ?ElasticsearchIssuesQueryBackend $elasticsearchIssuesQueryBackend = null;

    public function getElasticsearchIssuesQueryBackend(): ElasticsearchIssuesQueryBackend
    {
        return $this->elasticsearchIssuesQueryBackend ??= new ElasticsearchIssuesQueryBackend(
            IssuesRegistrySettings::fromEnvironmentForElasticsearch()
        );
    }

    /** Replaces the backend (tests, other settings); null = from the environment again */
    public function setElasticsearchIssuesQueryBackend(?ElasticsearchIssuesQueryBackend $elasticsearchIssuesQueryBackend): static
    {
        $this->elasticsearchIssuesQueryBackend = $elasticsearchIssuesQueryBackend;
        return $this;
    }

    /**
     * The error groups of the window, ranked by count.
     *
     * @param string[] $levels
     * @throws InternalErrorException The index is not configured or the query failed
     */
    public function findErrorGroups(
        int $hours = RuntimeErrorAggregationQuery::DEFAULT_HOURS,
        array $levels = RuntimeErrorAggregationQuery::DEFAULT_LEVELS,
        int $size = RuntimeErrorAggregationQuery::DEFAULT_SIZE,
        string $groupingDimension = RuntimeErrorAggregationQuery::DEFAULT_DIMENSION,
        string $logstashEnvironment = RuntimeErrorAggregationQuery::DEFAULT_ENVIRONMENT,
        ?int $accountId = null,
        ?int $locationId = null,
        ?string $searchTerm = null,
        ?string $workspace = null,
        ?int $minutes = null
    ): RuntimeErrorGroups {
        $elasticsearchIssuesQueryBackend = $this->getElasticsearchIssuesQueryBackend();
        $fieldNames = $elasticsearchIssuesQueryBackend->getFieldNames();
        $runtimeErrorAggregationQuery = RuntimeErrorAggregationQuery::build(
            $hours,
            $levels,
            $size,
            null,
            $groupingDimension,
            $accountId,
            $locationId,
            $searchTerm,
            $workspace,
            $minutes,
            $fieldNames
        );
        $response = $elasticsearchIssuesQueryBackend->search(
            $elasticsearchIssuesQueryBackend->getLogIndexPatternForEnvironment($logstashEnvironment),
            $runtimeErrorAggregationQuery->toGroupsBody()
        );
        return RuntimeErrorGroups::buildFromAggregation($response['aggregations'] ?? [], $fieldNames);
    }

    /**
     * The error count over time.
     *
     * @param string[] $levels
     * @throws InternalErrorException The index is not configured or the query failed
     */
    public function findErrorTrend(
        int $hours = RuntimeErrorAggregationQuery::DEFAULT_HOURS,
        array $levels = RuntimeErrorAggregationQuery::DEFAULT_LEVELS,
        ?string $fixedInterval = null,
        string $logstashEnvironment = RuntimeErrorAggregationQuery::DEFAULT_ENVIRONMENT,
        ?string $workspace = null
    ): RuntimeErrorTrend {
        $elasticsearchIssuesQueryBackend = $this->getElasticsearchIssuesQueryBackend();
        $runtimeErrorAggregationQuery = RuntimeErrorAggregationQuery::build(
            $hours,
            $levels,
            RuntimeErrorAggregationQuery::DEFAULT_SIZE,
            $fixedInterval,
            workspace: $workspace,
            fieldNames: $elasticsearchIssuesQueryBackend->getFieldNames()
        );
        $response = $elasticsearchIssuesQueryBackend->search(
            $elasticsearchIssuesQueryBackend->getLogIndexPatternForEnvironment($logstashEnvironment),
            $runtimeErrorAggregationQuery->toTrendBody()
        );
        return RuntimeErrorTrend::buildFromDateHistogram($response['aggregations'] ?? []);
    }

    /**
     * One group's detail; a key absent in the window gives an empty detail.
     *
     * @param string[] $levels
     * @throws InternalErrorException The index is not configured or the query failed
     */
    public function findErrorGroupDetail(
        string $groupKey,
        int $hours = RuntimeErrorAggregationQuery::DEFAULT_HOURS,
        array $levels = RuntimeErrorAggregationQuery::DEFAULT_LEVELS,
        ?string $fixedInterval = null,
        int $sampleSize = RuntimeErrorDetailAggregationQuery::DEFAULT_SAMPLE_SIZE,
        string $groupingDimension = RuntimeErrorAggregationQuery::DEFAULT_DIMENSION,
        string $logstashEnvironment = RuntimeErrorAggregationQuery::DEFAULT_ENVIRONMENT,
        ?string $workspace = null
    ): RuntimeErrorGroupDetail {
        $elasticsearchIssuesQueryBackend = $this->getElasticsearchIssuesQueryBackend();
        $fieldNames = $elasticsearchIssuesQueryBackend->getFieldNames();
        $runtimeErrorDetailAggregationQuery = RuntimeErrorDetailAggregationQuery::build(
            $groupKey,
            $hours,
            $levels,
            $fixedInterval,
            $sampleSize,
            $groupingDimension,
            $workspace,
            $fieldNames
        );
        $response = $elasticsearchIssuesQueryBackend->search(
            $elasticsearchIssuesQueryBackend->getLogIndexPatternForEnvironment($logstashEnvironment),
            $runtimeErrorDetailAggregationQuery->toDetailBody()
        );
        return RuntimeErrorGroupDetail::buildFromAggregation($response['aggregations'] ?? [], new RuntimeErrorFieldRedactor(), $fieldNames);
    }
}
