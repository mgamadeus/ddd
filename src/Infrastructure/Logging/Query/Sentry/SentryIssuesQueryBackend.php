<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Sentry;

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
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Sentry as the issues query backend, over its organization events API (Discover) — for applications whose events
 * reach Sentry through `app:issues:forward-sentry`, which sets the tags `group_hash`, `issue_hash`, `entry_point`,
 * `route`, `account_id` and `section` this backend reads.
 *
 * - `ISSUES_QUERY_URL`: `https://sentry.io` (default) or the self-hosted / regional base URL.
 * - `ISSUES_QUERY_SELECTOR`: `<organization slug>/<project slug>`.
 * - `ISSUES_QUERY_TOKEN`: an auth token with `event:read` (and `org:read` for the probe).
 *
 * Aggregation: `count()` per (group_hash, issue_hash, entry_point), paged through the `Link` header cursors.
 */
class SentryIssuesQueryBackend implements IssuesQueryBackend
{
    public const string DEFAULT_URL = 'https://sentry.io';

    public const int PAGE_SIZE = 100;

    public const int MAX_PAGES = 100;

    /** Error events: error and fatal, or uncaught */
    public const string ERROR_QUERY = '(level:[error,fatal] OR error.handled:0)';

    public const array EVENT_FIELDS = [
        'id', 'timestamp', 'message', 'level', 'error.type', 'release', 'environment',
        'tags[group_hash]', 'tags[issue_hash]', 'tags[entry_point]', 'tags[route]', 'tags[account_id]', 'tags[section]', 'tags[workspace]',
    ];

    protected ?ClientInterface $httpClient = null;

    public function __construct(protected IssuesRegistrySettings $settings, ?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient;
    }

    public function getName(): string
    {
        return IssuesRegistrySettings::BACKEND_SENTRY;
    }

    public function aggregateErrors(DateTime $from, DateTime $to): IssueHashCounts
    {
        $issueHashCounts = new IssueHashCounts();
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->requestEvents([
                'field' => ['tags[group_hash]', 'tags[issue_hash]', 'tags[entry_point]', 'count()'],
                'query' => $this->buildProjectQuery() . ' has:group_hash ' . self::ERROR_QUERY,
                'start' => $this->formatTime($from),
                'end' => $this->formatTime($to),
                'sort' => '-count()',
                'per_page' => self::PAGE_SIZE,
                'cursor' => $cursor,
            ]);
            foreach ($this->decodeRows($response) as $row) {
                $issueHashCount = new IssueHashCount(
                    (string)($row['tags[group_hash]'] ?? ''),
                    (string)($row['tags[issue_hash]'] ?? ''),
                    (string)($row['tags[entry_point]'] ?? ''),
                    (int)($row['count()'] ?? 0),
                );
                if ($issueHashCount->groupHash !== '') {
                    $issueHashCounts->add($issueHashCount);
                }
            }
            $cursor = $this->getNextCursor($response);
            if ($cursor === null) {
                break;
            }
        }
        return $issueHashCounts;
    }

    public function searchEvents(IssueEventSearch $issueEventSearch): IssueEvents
    {
        $queryParts = [$this->buildProjectQuery()];
        $queryParts[] = $issueEventSearch->levels !== []
            ? 'level:[' . implode(',', array_map([$this, 'mapLevel'], $issueEventSearch->levels)) . ']'
            : self::ERROR_QUERY;
        foreach ([
            'group_hash' => $issueEventSearch->groupHash,
            'issue_hash' => $issueEventSearch->issueHash,
            'route' => $issueEventSearch->routeName,
            'account_id' => $issueEventSearch->accountId,
            'workspace' => $issueEventSearch->workspace,
        ] as $tag => $value) {
            if ($value !== null && $value !== '') {
                $queryParts[] = $tag . ':' . $this->quote($value);
            }
        }
        if ($issueEventSearch->text !== null && $issueEventSearch->text !== '') {
            $queryParts[] = $this->quote($issueEventSearch->text);
        }
        $response = $this->requestEvents([
            'field' => self::EVENT_FIELDS,
            'query' => implode(' ', array_filter($queryParts)),
            'start' => $this->formatTime($issueEventSearch->from),
            'end' => $this->formatTime($issueEventSearch->to),
            'sort' => $issueEventSearch->oldestFirst ? 'timestamp' : '-timestamp',
            'per_page' => min(self::PAGE_SIZE, $issueEventSearch->limit),
        ]);
        $issueEvents = new IssueEvents();
        foreach ($this->decodeRows($response) as $row) {
            $issueEvent = $this->buildIssueEvent($row);
            $issueEvents->add($issueEvent);
        }
        return $issueEvents;
    }

    /**
     * Sentry's `events-stats` (`yAxis=count()`) with the step as interval (`<n>m` / `<n>h` / `<n>d`; Sentry rounds
     * unsupported intervals itself).
     */
    public function trend(TimeInterval $interval, int $stepSeconds, ?string $groupHash = null): DateTimeValueSequence
    {
        $from = $interval->startDate;
        $to = $interval->endDate;
        $query = $this->buildProjectQuery() . ' has:group_hash ' . self::ERROR_QUERY;
        if ($groupHash !== null && $groupHash !== '') {
            $query .= ' group_hash:' . $this->quote($groupHash);
        }
        $response = $this->requestOrganizationApi('events-stats', [
            'yAxis' => 'count()',
            'query' => trim($query),
            'start' => $this->formatTime(DateTime::fromTimestamp(DateTimeValueSequence::alignToStep($from->getTimestamp(), $stepSeconds))),
            'end' => $this->formatTime($to),
            'interval' => $this->formatInterval($stepSeconds),
        ]);
        $decoded = json_decode((string)$response->getBody(), true);
        $countsByTimestamp = [];
        foreach ((is_array($decoded['data'] ?? null) ? $decoded['data'] : []) as $point) {
            if (!is_array($point) || !isset($point[0])) {
                continue;
            }
            $count = 0;
            foreach ((array)($point[1] ?? []) as $series) {
                $count += (int)($series['count'] ?? 0);
            }
            $countsByTimestamp[(int)$point[0]] = ($countsByTimestamp[(int)$point[0]] ?? 0) + $count;
        }
        return DateTimeValueSequence::fromValuesByTimestamp($groupHash ?? 'errors', $from, $to, $stepSeconds, $countsByTimestamp);
    }

    protected function formatInterval(int $stepSeconds): string
    {
        return match (true) {
            $stepSeconds % 86400 === 0 => intdiv($stepSeconds, 86400) . 'd',
            $stepSeconds % 3600 === 0 => intdiv($stepSeconds, 3600) . 'h',
            default => max(1, intdiv($stepSeconds, 60)) . 'm',
        };
    }

    public function probe(): IssuesBackendProbe
    {
        try {
            $response = $this->requestEvents([
                'field' => ['count()'],
                'query' => $this->buildProjectQuery() . ' has:group_hash',
                'statsPeriod' => '24h',
            ]);
            $rows = $this->decodeRows($response);
            return new IssuesBackendProbe($this->getName(), true, sprintf('%d forwarded events within 24 h', (int)($rows[0]['count()'] ?? 0)));
        } catch (Throwable $throwable) {
            return new IssuesBackendProbe($this->getName(), false, $throwable->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $row One decoded result row
     */
    protected function buildIssueEvent(array $row): IssueEvent
    {
        // Discover returns some fields (error.type) as a list: the first value
        $rowString = static function (string $field) use ($row): ?string {
            $value = $row[$field] ?? null;
            $value = is_array($value) ? ($value[0] ?? null) : $value;
            return is_scalar($value) && $value !== '' ? (string)$value : null;
        };
        return new IssueEvent(
            timestamp: $rowString('timestamp') !== null ? DateTime::fromString((string)$rowString('timestamp')) : null,
            level: $rowString('level'),
            message: $rowString('message'),
            eventId: $rowString('id'),
            groupHash: $rowString('tags[group_hash]'),
            issueHash: $rowString('tags[issue_hash]'),
            entryPoint: $rowString('tags[entry_point]'),
            exceptionType: $rowString('error.type'),
            section: $rowString('tags[section]'),
            environment: $rowString('environment'),
            release: $rowString('release'),
            routeName: $rowString('tags[route]'),
            accountId: $rowString('tags[account_id]'),
            workspace: $rowString('tags[workspace]'),
            rawJson: (string)json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /** `project:<slug>` of the selector `<organization>/<project>` */
    protected function buildProjectQuery(): string
    {
        $projectSlug = explode('/', $this->settings->querySelector, 2)[1] ?? '';
        return $projectSlug !== '' ? 'project:' . $projectSlug : '';
    }

    /**
     * @throws InternalErrorException No organization in the selector
     */
    protected function getOrganizationSlug(): string
    {
        $organizationSlug = explode('/', $this->settings->querySelector, 2)[0];
        if ($organizationSlug === '') {
            throw new InternalErrorException('Sentry backend: set ISSUES_QUERY_SELECTOR to "<organization>/<project>"');
        }
        return $organizationSlug;
    }

    protected function mapLevel(string $level): string
    {
        return match (strtolower($level)) {
            'critical', 'alert', 'emergency' => 'fatal',
            'notice' => 'info',
            default => strtolower($level),
        };
    }

    protected function quote(string $value): string
    {
        return '"' . str_replace('"', '\\"', $value) . '"';
    }

    protected function formatTime(DateTime $dateTime): string
    {
        return gmdate('Y-m-d\TH:i:s', $dateTime->getTimestamp());
    }

    /**
     * @param array<string, string|int|string[]|null> $queryParameters Query string of the events API
     * @throws InternalErrorException Sentry answered with an error or was not reachable
     */
    protected function requestEvents(array $queryParameters): ResponseInterface
    {
        return $this->requestOrganizationApi('events', $queryParameters);
    }

    /**
     * @param array<string, string|int|string[]|null> $queryParameters Query string of the organization API
     * @throws InternalErrorException Sentry answered with an error or was not reachable
     */
    protected function requestOrganizationApi(string $endpoint, array $queryParameters): ResponseInterface
    {
        $queryString = [];
        foreach ($queryParameters as $name => $value) {
            foreach ((array)$value as $singleValue) {
                if ($singleValue !== null && $singleValue !== '') {
                    $queryString[] = rawurlencode($name) . '=' . rawurlencode((string)$singleValue);
                }
            }
        }
        $baseUrl = rtrim($this->settings->queryUrl !== '' ? $this->settings->queryUrl : self::DEFAULT_URL, '/');
        $url = sprintf('%s/api/0/organizations/%s/%s/?%s', $baseUrl, rawurlencode($this->getOrganizationSlug()), $endpoint, implode('&', $queryString));
        try {
            $response = $this->getHttpClient()->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . (string)$this->settings->queryToken, 'Accept' => 'application/json'],
                'timeout' => $this->settings->queryTimeoutSeconds,
                'connect_timeout' => $this->settings->queryTimeoutSeconds,
                'verify' => $this->settings->queryTlsVerify,
                'http_errors' => false,
            ]);
        } catch (Throwable $throwable) {
            throw new InternalErrorException('Sentry query failed: ' . $throwable->getMessage());
        }
        if ($response->getStatusCode() >= 300) {
            throw new InternalErrorException(sprintf(
                'Sentry query failed: HTTP %d %s',
                $response->getStatusCode(),
                mb_substr((string)$response->getBody(), 0, 500)
            ));
        }
        return $response;
    }

    /**
     * @return array<int, array<string, mixed>> The decoded `data` rows
     */
    protected function decodeRows(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /** The cursor of the next page from the `Link` header, when it has results */
    protected function getNextCursor(ResponseInterface $response): ?string
    {
        foreach (explode(',', $response->getHeaderLine('Link')) as $link) {
            if (str_contains($link, 'rel="next"') && str_contains($link, 'results="true"')
                && preg_match('/cursor="([^"]+)"/', $link, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    protected function getHttpClient(): ClientInterface
    {
        return $this->httpClient ??= new Client();
    }
}
