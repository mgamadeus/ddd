<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The runtime-error queries against an Elasticsearch / OpenSearch log index: the parameters and the pure assembly of
 * the GROUPS and TREND `_search` bodies (the bodies of rankingCoach's runtime-error dashboard, byte for byte with the
 * default {@see ElasticsearchFieldNames}).
 *
 * - Filter: `@timestamp` look-back range + `log.level` (keyword) ∈ levels, optional account / location / workspace
 *   terms and a case-insensitive CONTAINS search on route and error type.
 * - Grouping: COARSE (default) = `multi_terms` on error type + throw-site file, both with a `missing "—"` sentinel;
 *   FINGERPRINT = `terms` on `error.fingerprint_short`. The outer aggregation key is always
 *   {@see self::OUTER_AGG_KEY}.
 * - Per group: `latest` (newest occurrence), `affected_locations` (cardinality), `first_seen`.
 * - Trend: a `date_histogram` whose interval scales with the window.
 */
class RuntimeErrorAggregationQuery extends ValueObject
{
    public const string LEVEL_ERROR = 'ERROR';
    public const string LEVEL_CRITICAL = 'CRITICAL';

    /** The outer aggregation key of the groups and the detail body (read by the result factories) */
    public const string OUTER_AGG_KEY = 'by_fingerprint';

    /** Groups on `error.fingerprint_short` (wire token of `groupingDimension`) */
    public const string DIMENSION_FINGERPRINT = 'FINGERPRINT';

    /** Groups on error type + throw-site file (wire token of `groupingDimension`, the default) */
    public const string DIMENSION_COARSE = 'COARSE';

    public const string DEFAULT_DIMENSION = self::DIMENSION_COARSE;

    /** Reads the production index pattern (wire token of `logstashEnvironment`, the default) */
    public const string ENVIRONMENT_PRODUCTION = 'PRODUCTION';

    /** Reads the development index pattern (wire token of `logstashEnvironment`) */
    public const string ENVIRONMENT_DEVELOPMENT = 'DEVELOPMENT';

    public const string DEFAULT_ENVIRONMENT = self::ENVIRONMENT_PRODUCTION;

    /** The valid environment tokens, the default first */
    public const array ENVIRONMENTS = [self::ENVIRONMENT_PRODUCTION, self::ENVIRONMENT_DEVELOPMENT];

    public const int DEFAULT_HOURS = 24;

    public const int DEFAULT_SIZE = 50;

    public const array DEFAULT_LEVELS = [self::LEVEL_ERROR, self::LEVEL_CRITICAL];

    /** Trend interval up to 6 h */
    public const string INTERVAL_FINE = '10m';

    /** Trend interval up to 24 h */
    public const string INTERVAL_MEDIUM = '1h';

    /** Trend interval beyond 24 h */
    public const string INTERVAL_COARSE = '6h';

    /** The string fields a search term is matched against (before the keyword suffix), OR'd */
    public const array SEARCH_TERM_FIELDS = ['route_name', 'error.type'];

    /** The `_source` fields of an occurrence (dotted keys, flat in the response) */
    public const array LATEST_SOURCE_FIELDS = [
        'message',
        'error.type',
        'error.stack_trace',
        'route_name',
        '@timestamp',
        'error.fingerprint_hash',
        'error.fingerprint_short',
        'lastLocationId',
        'error.code',
        'error.kind',
        'log.origin.file.name',
        'log.origin.file.line',
        'environment',
        'workspace',
    ];

    /** The missing-value sentinel of the composite keys */
    public const string MISSING_SENTINEL = '—';

    /** Look-back window in hours */
    public int $hours = self::DEFAULT_HOURS;

    /** @var string[] Log levels to include (upper case, as the ECS formatter writes them) */
    public array $levels = self::DEFAULT_LEVELS;

    /** Top N groups (terms-agg size cap) */
    public int $size = self::DEFAULT_SIZE;

    /** Explicit trend interval; null = derived from the window ({@see self::resolveTrendInterval()}) */
    public ?string $fixedInterval = null;

    /** COARSE or FINGERPRINT */
    public string $groupingDimension = self::DEFAULT_DIMENSION;

    /** Only this account (`account.id`, numeric) */
    public ?int $accountId = null;

    /** Only this location (the numeric location field) */
    public ?int $locationId = null;

    /** Case-insensitive CONTAINS on route or error type; wildcard metacharacters are matched literally */
    public ?string $searchTerm = null;

    /** Only this dev workspace */
    public ?string $workspace = null;

    /** Look-back window in minutes — overrides {@see self::$hours} when set */
    public ?int $minutes = null;

    public ElasticsearchFieldNames $fieldNames;

    /**
     * @param string[] $levels Empty = the ERROR + CRITICAL default (a `terms` filter is never empty)
     */
    public static function build(
        int $hours = self::DEFAULT_HOURS,
        array $levels = self::DEFAULT_LEVELS,
        int $size = self::DEFAULT_SIZE,
        ?string $fixedInterval = null,
        string $groupingDimension = self::DEFAULT_DIMENSION,
        ?int $accountId = null,
        ?int $locationId = null,
        ?string $searchTerm = null,
        ?string $workspace = null,
        ?int $minutes = null,
        ?ElasticsearchFieldNames $fieldNames = null,
    ): static {
        $runtimeErrorAggregationQuery = new static();
        $runtimeErrorAggregationQuery->hours = $hours;
        $runtimeErrorAggregationQuery->minutes = $minutes;
        $runtimeErrorAggregationQuery->levels = $levels === [] ? self::DEFAULT_LEVELS : array_values($levels);
        $runtimeErrorAggregationQuery->size = $size;
        $runtimeErrorAggregationQuery->fixedInterval = $fixedInterval;
        $runtimeErrorAggregationQuery->groupingDimension = $groupingDimension;
        $runtimeErrorAggregationQuery->accountId = $accountId;
        $runtimeErrorAggregationQuery->locationId = $locationId;
        $runtimeErrorAggregationQuery->searchTerm = $searchTerm;
        $runtimeErrorAggregationQuery->workspace = $workspace;
        $runtimeErrorAggregationQuery->fieldNames = $fieldNames ?? new ElasticsearchFieldNames();
        return $runtimeErrorAggregationQuery;
    }

    /**
     * The comma-separated level list (`ERROR,CRITICAL`) as upper-case levels; blank = [] (→ the default).
     *
     * @return string[]
     */
    public static function parseLevels(?string $commaSeparatedLevels): array
    {
        if ($commaSeparatedLevels === null || trim($commaSeparatedLevels) === '') {
            return [];
        }
        $upperCasedLevels = array_map(
            static fn(string $levelToken): string => strtoupper(trim($levelToken)),
            explode(',', $commaSeparatedLevels)
        );
        return array_values(array_filter($upperCasedLevels, static fn(string $levelToken): bool => $levelToken !== ''));
    }

    /** The explicit interval, else 1m for a minutes window, 10m (≤ 6 h), 1h (≤ 24 h), 6h */
    public function resolveTrendInterval(): string
    {
        if ($this->fixedInterval !== null && $this->fixedInterval !== '') {
            return $this->fixedInterval;
        }
        if ($this->minutes !== null && $this->minutes > 0) {
            return '1m';
        }
        if ($this->hours <= 6) {
            return self::INTERVAL_FINE;
        }
        if ($this->hours <= 24) {
            return self::INTERVAL_MEDIUM;
        }
        return self::INTERVAL_COARSE;
    }

    /** The lower bound of the `@timestamp` range: `now-{minutes}m` or `now-{hours}h` */
    public function lookbackExpression(): string
    {
        if ($this->minutes !== null && $this->minutes > 0) {
            return 'now-' . $this->minutes . 'm';
        }
        return 'now-' . $this->hours . 'h';
    }

    /**
     * The shared `bool.filter` clauses (Elasticsearch query DSL, sent as is).
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildFilterClauses(): array
    {
        $filterClauses = [
            ['range' => ['@timestamp' => ['gte' => $this->lookbackExpression()]]],
            ['terms' => [$this->fieldNames->keyword('log.level') => array_values($this->levels)]],
        ];
        if ($this->accountId !== null) {
            $filterClauses[] = ['term' => ['account.id' => $this->accountId]];
        }
        if ($this->locationId !== null) {
            $filterClauses[] = ['term' => [$this->fieldNames->locationField => $this->locationId]];
        }
        if ($this->searchTerm !== null && trim($this->searchTerm) !== '') {
            $filterClauses[] = $this->buildSearchClause(trim($this->searchTerm));
        }
        if ($this->workspace !== null && trim($this->workspace) !== '') {
            $filterClauses[] = ['term' => [$this->fieldNames->keyword($this->fieldNames->workspaceField) => $this->workspace]];
        }
        return $filterClauses;
    }

    /**
     * Case-insensitive CONTAINS on {@see self::SEARCH_TERM_FIELDS}: `\`, `*`, `?` are escaped, so the term is a literal.
     *
     * @return array<string, mixed>
     */
    protected function buildSearchClause(string $searchTerm): array
    {
        $escapedTerm = str_replace(['\\', '*', '?'], ['\\\\', '\\*', '\\?'], $searchTerm);
        $containsPattern = '*' . $escapedTerm . '*';
        $shouldClauses = [];
        foreach (self::SEARCH_TERM_FIELDS as $searchField) {
            $shouldClauses[] = ['wildcard' => [
                $this->fieldNames->keyword($searchField) => ['value' => $containsPattern, 'case_insensitive' => true],
            ]];
        }
        return ['bool' => ['should' => $shouldClauses, 'minimum_should_match' => 1]];
    }

    /**
     * The GROUPS body: `size: 0`, the filter, the grouping aggregation ranked by count with the shared sub-aggregations.
     *
     * @return array<string, mixed>
     */
    public function toGroupsBody(): array
    {
        $groupingAggregation = $this->groupingDimension === self::DIMENSION_COARSE
            ? $this->buildCoarseGroupingAgg()
            : $this->buildFingerprintGroupingAgg();
        return [
            'size' => 0,
            'query' => ['bool' => ['filter' => $this->buildFilterClauses()]],
            'aggs' => [self::OUTER_AGG_KEY => $groupingAggregation],
        ];
    }

    /** @return array<string, mixed> */
    protected function buildFingerprintGroupingAgg(): array
    {
        return [
            'terms' => [
                'field' => $this->fieldNames->keyword('error.fingerprint_short'),
                'size' => $this->size,
                'order' => ['_count' => 'desc'],
            ],
            'aggs' => $this->buildSharedGroupSubAggs(),
        ];
    }

    /** @return array<string, mixed> */
    protected function buildCoarseGroupingAgg(): array
    {
        return [
            'multi_terms' => [
                'terms' => $this->buildCoarseTerms(),
                'size' => $this->size,
                'order' => ['_count' => 'desc'],
            ],
            'aggs' => $this->buildSharedGroupSubAggs(),
        ];
    }

    /**
     * The two terms of the COARSE composite key.
     *
     * @return array<int, array<string, string>>
     */
    public function buildCoarseTerms(): array
    {
        return [
            ['field' => $this->fieldNames->keyword('error.type'), 'missing' => self::MISSING_SENTINEL],
            ['field' => $this->fieldNames->keyword('log.origin.file.name'), 'missing' => self::MISSING_SENTINEL],
        ];
    }

    /**
     * `latest`, `affected_locations`, `first_seen` — shared by the groups and the detail body.
     *
     * @return array<string, mixed>
     */
    public function buildSharedGroupSubAggs(): array
    {
        return [
            'latest' => [
                'top_hits' => [
                    'size' => 1,
                    'sort' => [['@timestamp' => 'desc']],
                    '_source' => $this->getSourceFields(),
                ],
            ],
            'affected_locations' => [
                'cardinality' => ['field' => $this->fieldNames->locationField],
            ],
            'first_seen' => [
                'min' => ['field' => '@timestamp', 'format' => 'strict_date_optional_time'],
            ],
        ];
    }

    /**
     * {@see self::LATEST_SOURCE_FIELDS} with the configured location and workspace field.
     *
     * @return string[]
     */
    public function getSourceFields(): array
    {
        return array_map(fn(string $sourceField): string => match ($sourceField) {
            'lastLocationId' => $this->fieldNames->locationField,
            'workspace' => $this->fieldNames->workspaceField,
            default => $sourceField,
        }, self::LATEST_SOURCE_FIELDS);
    }

    /**
     * The TREND body: `size: 0`, the filter, a `date_histogram` on `@timestamp`.
     *
     * @return array<string, mixed>
     */
    public function toTrendBody(): array
    {
        return [
            'size' => 0,
            'query' => ['bool' => ['filter' => $this->buildFilterClauses()]],
            'aggs' => [
                'trend' => [
                    'date_histogram' => [
                        'field' => '@timestamp',
                        'fixed_interval' => $this->resolveTrendInterval(),
                    ],
                ],
            ],
        ];
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
