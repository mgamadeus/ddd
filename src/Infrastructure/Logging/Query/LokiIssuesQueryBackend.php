<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

/**
 * Loki as the issues query backend (HTTP API). The issues lines are JSON, so every query parses them with `| json`, which
 * turns the dotted keys into labels with underscores (`error.group_hash` → `error_group_hash`).
 *
 * - Aggregation: an instant metric query `sum by (error_group_hash, error_issue_hash, error_entry_point)
 *   (count_over_time(<selector> | json | … [<range>]))` — counted in Loki, not capped like raw lines (5000).
 * - Events: `query_range` with the line filters, newest (or oldest) first.
 *
 * `ISSUES_QUERY_SELECTOR` is the stream selector of the issues lines (e.g. `{service="dineful", environment="prod"}`);
 * user + token = basic auth, token alone = bearer; `ISSUES_QUERY_TENANT` sets X-Scope-OrgID.
 */
class LokiIssuesQueryBackend implements IssuesQueryBackend
{
    /** Error events: error and above, or uncaught (error.handled=false) */
    public const string ERROR_FILTER = '| log_level=~"(?i)(error|critical|alert|emergency)" or error_handled="false"';

    protected ?ClientInterface $httpClient = null;

    public function __construct(protected IssuesRegistrySettings $settings, ?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient;
    }

    public function getName(): string
    {
        return IssuesRegistrySettings::BACKEND_LOKI;
    }

    public function aggregateErrors(DateTime $from, DateTime $to): IssueHashCounts
    {
        $rangeSeconds = max(1, $to->getTimestamp() - $from->getTimestamp());
        $query = sprintf(
            'sum by (error_group_hash, error_issue_hash, error_entry_point) (count_over_time(%s | json | error_group_hash!="" %s [%ds]))',
            $this->getSelector(),
            self::ERROR_FILTER,
            $rangeSeconds
        );
        $response = $this->request('/loki/api/v1/query', ['query' => $query, 'time' => $this->toNanoseconds($to)]);
        $issueHashCounts = new IssueHashCounts();
        foreach ($response['data']['result'] ?? [] as $series) {
            $metric = $series['metric'] ?? [];
            $issueHashCount = new IssueHashCount(
                (string)($metric['error_group_hash'] ?? ''),
                (string)($metric['error_issue_hash'] ?? ''),
                (string)($metric['error_entry_point'] ?? ''),
                (int)round((float)($series['value'][1] ?? 0)),
            );
            if ($issueHashCount->groupHash !== '') {
                $issueHashCounts->add($issueHashCount);
            }
        }
        return $issueHashCounts;
    }

    public function searchEvents(IssueEventSearch $issueEventSearch): IssueEvents
    {
        $query = $this->getSelector();
        if ($issueEventSearch->text !== null && $issueEventSearch->text !== '') {
            $query .= ' |~ ' . $this->quote('(?i)' . preg_quote($issueEventSearch->text));
        }
        $query .= ' | json';
        $query .= $issueEventSearch->levels !== []
            ? ' | log_level=~' . $this->quote('(?i)(' . implode('|', array_map('preg_quote', $issueEventSearch->levels)) . ')')
            : ' ' . self::ERROR_FILTER;
        foreach ([
            'error_group_hash' => $issueEventSearch->groupHash,
            'error_issue_hash' => $issueEventSearch->issueHash,
            'route_name' => $issueEventSearch->routeName,
            'account_id' => $issueEventSearch->accountId,
            'service_workspace' => $issueEventSearch->workspace,
        ] as $label => $value) {
            if ($value !== null && $value !== '') {
                $query .= ' | ' . $label . '=' . $this->quote($value);
            }
        }
        $response = $this->request('/loki/api/v1/query_range', [
            'query' => $query,
            'start' => $this->toNanoseconds($issueEventSearch->from),
            'end' => $this->toNanoseconds($issueEventSearch->to),
            'limit' => $issueEventSearch->limit,
            'direction' => $issueEventSearch->oldestFirst ? 'forward' : 'backward',
        ]);
        $timestampedLines = [];
        foreach ($response['data']['result'] ?? [] as $stream) {
            foreach ($stream['values'] ?? [] as [$nanoseconds, $line]) {
                $timestampedLines[] = [(string)$nanoseconds, (string)$line];
            }
        }
        // streams come one after the other; the order holds across all of them
        usort($timestampedLines, static fn(array $left, array $right): int => $issueEventSearch->oldestFirst
            ? strcmp(str_pad($left[0], 20, '0', STR_PAD_LEFT), str_pad($right[0], 20, '0', STR_PAD_LEFT))
            : strcmp(str_pad($right[0], 20, '0', STR_PAD_LEFT), str_pad($left[0], 20, '0', STR_PAD_LEFT)));
        $issueEvents = new IssueEvents();
        foreach (array_slice($timestampedLines, 0, $issueEventSearch->limit) as [, $line]) {
            $issueEvent = IssueEvent::fromJsonLine($line);
            if ($issueEvent !== null) {
                $issueEvents->add($issueEvent);
            }
        }
        return $issueEvents;
    }

    public function probe(): IssuesBackendProbe
    {
        try {
            $response = $this->getHttpClient()->request('GET', $this->settings->queryUrl . '/ready', $this->getRequestOptions());
            return new IssuesBackendProbe($this->getName(), $response->getStatusCode() === 200, trim((string)$response->getBody()));
        } catch (Throwable $throwable) {
            return new IssuesBackendProbe($this->getName(), false, $throwable->getMessage());
        }
    }

    protected function getSelector(): string
    {
        return $this->settings->querySelector !== '' ? $this->settings->querySelector : '{job=~".+"}';
    }

    /** A LogQL string literal */
    protected function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    protected function toNanoseconds(DateTime $dateTime): string
    {
        return $dateTime->format('U') . str_pad($dateTime->format('u'), 6, '0') . '000';
    }

    /**
     * @param array<string, string|int> $queryParameters
     * @return array<string, mixed> The decoded response
     * @throws InternalErrorException The backend answered with an error or was not reachable
     */
    protected function request(string $path, array $queryParameters): array
    {
        try {
            $response = $this->getHttpClient()->request(
                'GET',
                $this->settings->queryUrl . $path,
                [...$this->getRequestOptions(), 'query' => $queryParameters]
            );
            $decoded = json_decode((string)$response->getBody(), true);
        } catch (Throwable $throwable) {
            throw new InternalErrorException('Loki query failed: ' . $throwable->getMessage());
        }
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new InternalErrorException('Loki query failed: ' . substr((string)json_encode($decoded), 0, 500));
        }
        return $decoded;
    }

    /** @return array<string, mixed> Guzzle options: timeout, auth, tenant */
    protected function getRequestOptions(): array
    {
        $options = ['timeout' => $this->settings->queryTimeoutSeconds, 'headers' => []];
        if ($this->settings->queryUser !== null) {
            $options['auth'] = [$this->settings->queryUser, (string)$this->settings->queryToken];
        } elseif ($this->settings->queryToken !== null) {
            $options['headers']['Authorization'] = 'Bearer ' . $this->settings->queryToken;
        }
        if ($this->settings->queryTenant !== null) {
            $options['headers']['X-Scope-OrgID'] = $this->settings->queryTenant;
        }
        return $options;
    }

    protected function getHttpClient(): ClientInterface
    {
        return $this->httpClient ??= new Client();
    }
}
