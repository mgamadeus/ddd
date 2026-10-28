<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Logging\Query\IssueEvent;
use DDD\Infrastructure\Logging\Query\IssueEvents;
use DDD\Infrastructure\Logging\Query\IssueEventSearch;
use DDD\Infrastructure\Logging\Query\IssueHashCount;
use DDD\Infrastructure\Logging\Query\IssueHashCounts;
use DDD\Infrastructure\Logging\Query\IssuesBackendProbe;
use DDD\Infrastructure\Logging\Query\IssuesQueryBackend;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

/**
 * Elasticsearch / OpenSearch as the issues query backend, over the plain `_search` HTTP API (ES 7, ES 8 and OpenSearch
 * alike; no client library). Read-only: only `_search` is called, also by {@see self::probe()} — a read-only user may
 * not see the cluster APIs.
 *
 * - `ISSUES_QUERY_URL` (or `ES_LOGS_SCHEME://ES_LOGS_HOST:ES_LOGS_PORT`), user + token = basic auth, token alone =
 *   bearer (an API key as `ApiKey …` is passed through as given).
 * - `ISSUES_QUERY_SELECTOR` (or `ES_LOGS_INDEX`) is the index pattern, `ISSUES_QUERY_SELECTOR_DEVELOPMENT` (or
 *   `ES_LOGS_INDEX_DEV`) the development one.
 * - Field names per mapping: {@see ElasticsearchFieldNames}.
 *
 * The registry aggregation is a `composite` aggregation paged with `after_key`, so no group is cut off by a size cap.
 */
class ElasticsearchIssuesQueryBackend implements IssuesQueryBackend
{
    /** Levels of an error event (upper case, as the ECS formatter writes them); uncaught (`error.handled: false`) too */
    public const array ERROR_LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public const int COMPOSITE_PAGE_SIZE = 1000;

    /** Safety cap of the composite paging: 100 000 distinct (group, issue, entry point) combinations per window */
    public const int MAX_COMPOSITE_PAGES = 100;

    protected ?ClientInterface $httpClient = null;

    public function __construct(protected IssuesRegistrySettings $settings, ?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient;
    }

    public function getName(): string
    {
        return $this->settings->queryBackend;
    }

    public function getFieldNames(): ElasticsearchFieldNames
    {
        return ElasticsearchFieldNames::fromSettings($this->settings);
    }

    /**
     * @throws InternalErrorException No index pattern configured
     */
    public function getDefaultLogIndexPattern(): string
    {
        if ($this->settings->querySelector === '') {
            throw new InternalErrorException('No Elasticsearch index pattern: set ISSUES_QUERY_SELECTOR (or ES_LOGS_INDEX)');
        }
        return $this->settings->querySelector;
    }

    /** The development index pattern; unset = the default one */
    public function getDevelopmentLogIndexPattern(): string
    {
        return $this->settings->querySelectorDevelopment !== ''
            ? $this->settings->querySelectorDevelopment
            : $this->getDefaultLogIndexPattern();
    }

    /** The index pattern of a `logstashEnvironment` token (DEVELOPMENT; anything else = PRODUCTION) */
    public function getLogIndexPatternForEnvironment(string $logstashEnvironment): string
    {
        return $logstashEnvironment === RuntimeErrorAggregationQuery::ENVIRONMENT_DEVELOPMENT
            ? $this->getDevelopmentLogIndexPattern()
            : $this->getDefaultLogIndexPattern();
    }

    /**
     * Runs a `_search` body (Elasticsearch query DSL, sent as is) and returns the decoded response.
     *
     * @param array<string, mixed> $searchBody
     * @return array<string, mixed>
     * @throws InternalErrorException The cluster answered with an error or was not reachable
     */
    public function search(string $indexPattern, array $searchBody): array
    {
        $path = '/' . implode(',', array_map('rawurlencode', explode(',', $indexPattern))) . '/_search';
        try {
            $response = $this->getHttpClient()->request('POST', $this->settings->queryUrl . $path, [
                ...$this->getRequestOptions(),
                'body' => json_encode($searchBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'http_errors' => false,
            ]);
        } catch (Throwable $throwable) {
            throw new InternalErrorException('Elasticsearch query failed: ' . $throwable->getMessage());
        }
        $responseBody = (string)$response->getBody();
        $decoded = json_decode($responseBody, true);
        if ($response->getStatusCode() >= 300 || !is_array($decoded)) {
            throw new InternalErrorException(sprintf(
                'Elasticsearch query failed: HTTP %d %s',
                $response->getStatusCode(),
                mb_substr($responseBody, 0, 500)
            ));
        }
        return $decoded;
    }

    /**
     * The error-event filter: level ∈ {@see self::ERROR_LEVELS} or uncaught.
     *
     * @return array<string, mixed>
     */
    protected function buildErrorClause(): array
    {
        return ['bool' => [
            'should' => [
                ['terms' => [$this->getFieldNames()->keyword('log.level') => self::ERROR_LEVELS]],
                ['term' => ['error.handled' => false]],
            ],
            'minimum_should_match' => 1,
        ]];
    }

    /** @return array<string, mixed> */
    protected function buildTimeRangeClause(DateTime $from, DateTime $to): array
    {
        return ['range' => ['@timestamp' => [
            'gte' => $from->format('Y-m-d\TH:i:s.vP'),
            'lte' => $to->format('Y-m-d\TH:i:s.vP'),
        ]]];
    }

    public function aggregateErrors(DateTime $from, DateTime $to): IssueHashCounts
    {
        $fieldNames = $this->getFieldNames();
        $issueHashCounts = new IssueHashCounts();
        $afterKey = null;
        for ($page = 0; $page < self::MAX_COMPOSITE_PAGES; $page++) {
            $composite = [
                'size' => self::COMPOSITE_PAGE_SIZE,
                'sources' => [
                    ['group_hash' => ['terms' => ['field' => $fieldNames->keyword('error.group_hash')]]],
                    ['issue_hash' => ['terms' => ['field' => $fieldNames->keyword('error.issue_hash'), 'missing_bucket' => true]]],
                    ['entry_point' => ['terms' => ['field' => $fieldNames->keyword('error.entry_point'), 'missing_bucket' => true]]],
                ],
            ];
            if ($afterKey !== null) {
                $composite['after'] = $afterKey;
            }
            $response = $this->search($this->getDefaultLogIndexPattern(), [
                'size' => 0,
                'track_total_hits' => false,
                'query' => ['bool' => ['filter' => [
                    $this->buildTimeRangeClause($from, $to),
                    ['exists' => ['field' => $fieldNames->keyword('error.group_hash')]],
                    $this->buildErrorClause(),
                ]]],
                'aggs' => ['issue_hashes' => ['composite' => $composite]],
            ]);
            $buckets = $response['aggregations']['issue_hashes']['buckets'] ?? [];
            foreach ($buckets as $bucket) {
                $issueHashCount = new IssueHashCount(
                    (string)($bucket['key']['group_hash'] ?? ''),
                    (string)($bucket['key']['issue_hash'] ?? ''),
                    (string)($bucket['key']['entry_point'] ?? ''),
                    (int)($bucket['doc_count'] ?? 0),
                );
                if ($issueHashCount->groupHash !== '') {
                    $issueHashCounts->add($issueHashCount);
                }
            }
            $afterKey = $response['aggregations']['issue_hashes']['after_key'] ?? null;
            if ($afterKey === null || count($buckets) < self::COMPOSITE_PAGE_SIZE) {
                break;
            }
        }
        return $issueHashCounts;
    }

    public function searchEvents(IssueEventSearch $issueEventSearch): IssueEvents
    {
        $fieldNames = $this->getFieldNames();
        $filterClauses = [$this->buildTimeRangeClause($issueEventSearch->from, $issueEventSearch->to)];
        $filterClauses[] = $issueEventSearch->levels !== []
            ? ['terms' => [$fieldNames->keyword('log.level') => array_map('strtoupper', $issueEventSearch->levels)]]
            : $this->buildErrorClause();
        foreach ([
            'route_name' => $issueEventSearch->routeName,
            'error.group_hash' => $issueEventSearch->groupHash,
            'error.issue_hash' => $issueEventSearch->issueHash,
            'service.workspace' => $issueEventSearch->workspace,
        ] as $field => $value) {
            if ($value !== null && $value !== '') {
                $filterClauses[] = ['term' => [$fieldNames->keyword($field) => $value]];
            }
        }
        if ($issueEventSearch->accountId !== null && $issueEventSearch->accountId !== '') {
            $filterClauses[] = ['term' => ['account.id' => is_numeric($issueEventSearch->accountId)
                ? (int)$issueEventSearch->accountId
                : $issueEventSearch->accountId]];
        }
        $query = ['bool' => ['filter' => $filterClauses]];
        if ($issueEventSearch->text !== null && $issueEventSearch->text !== '') {
            $query['bool']['must'] = [['simple_query_string' => [
                'query' => '"' . str_replace('"', '\\"', $issueEventSearch->text) . '"',
                'fields' => ['message', 'error.message', 'error.type', 'route_name'],
                'default_operator' => 'and',
            ]]];
        }
        $response = $this->search($this->getDefaultLogIndexPattern(), [
            'size' => $issueEventSearch->limit,
            'track_total_hits' => false,
            'sort' => [['@timestamp' => $issueEventSearch->oldestFirst ? 'asc' : 'desc']],
            'query' => $query,
        ]);
        $issueEvents = new IssueEvents();
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $issueEvent = IssueEvent::fromJsonLine((string)json_encode($hit['_source'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($issueEvent !== null) {
                $issueEvents->add($issueEvent);
            }
        }
        return $issueEvents;
    }

    /**
     * A `date_histogram` on `@timestamp` with `fixed_interval` = the step, `min_doc_count: 0` and `extended_bounds`
     * over the window (the shape of the runtime-error trend body, with the registry's error filter).
     */
    public function trend(TimeInterval $interval, int $stepSeconds, ?string $groupHash = null): DateTimeValueSequence
    {
        $from = $interval->startDate;
        $to = $interval->endDate;
        $filterClauses = [$this->buildTimeRangeClause($from, $to), $this->buildErrorClause()];
        if ($groupHash !== null && $groupHash !== '') {
            $filterClauses[] = ['term' => [$this->getFieldNames()->keyword('error.group_hash') => $groupHash]];
        }
        $response = $this->search($this->getDefaultLogIndexPattern(), [
            'size' => 0,
            'track_total_hits' => false,
            'query' => ['bool' => ['filter' => $filterClauses]],
            'aggs' => [
                'trend' => [
                    'date_histogram' => [
                        'field' => '@timestamp',
                        'fixed_interval' => $stepSeconds . 's',
                        'min_doc_count' => 0,
                        'extended_bounds' => [
                            'min' => DateTimeValueSequence::alignToStep($from->getTimestamp(), $stepSeconds) * 1000,
                            'max' => DateTimeValueSequence::alignToStep($to->getTimestamp(), $stepSeconds) * 1000,
                        ],
                    ],
                ],
            ],
        ]);
        $countsByTimestamp = [];
        foreach ($response['aggregations']['trend']['buckets'] ?? [] as $bucket) {
            $bucketStart = intdiv((int)($bucket['key'] ?? 0), 1000);
            $countsByTimestamp[$bucketStart] = ($countsByTimestamp[$bucketStart] ?? 0) + (int)($bucket['doc_count'] ?? 0);
        }
        return DateTimeValueSequence::fromValuesByTimestamp($groupHash ?? 'errors', $from, $to, $stepSeconds, $countsByTimestamp);
    }

    public function probe(): IssuesBackendProbe
    {
        try {
            $response = $this->search($this->getDefaultLogIndexPattern(), [
                'size' => 0,
                'track_total_hits' => true,
                'query' => ['range' => ['@timestamp' => ['gte' => 'now-24h']]],
            ]);
            return new IssuesBackendProbe(
                $this->getName(),
                true,
                sprintf('%d events within 24 h in %s', (int)($response['hits']['total']['value'] ?? 0), $this->getDefaultLogIndexPattern())
            );
        } catch (Throwable $throwable) {
            return new IssuesBackendProbe($this->getName(), false, $throwable->getMessage());
        }
    }

    /** @return array<string, mixed> Guzzle options: timeout, TLS, auth */
    protected function getRequestOptions(): array
    {
        $options = [
            'timeout' => $this->settings->queryTimeoutSeconds,
            'connect_timeout' => $this->settings->queryTimeoutSeconds,
            'verify' => $this->settings->queryTlsVerify,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ];
        if ($this->settings->queryUser !== null) {
            $options['auth'] = [$this->settings->queryUser, (string)$this->settings->queryToken];
        } elseif ($this->settings->queryToken !== null) {
            $options['headers']['Authorization'] = str_contains($this->settings->queryToken, ' ')
                ? $this->settings->queryToken
                : 'Bearer ' . $this->settings->queryToken;
        }
        return $options;
    }

    protected function getHttpClient(): ClientInterface
    {
        return $this->httpClient ??= new Client();
    }
}
