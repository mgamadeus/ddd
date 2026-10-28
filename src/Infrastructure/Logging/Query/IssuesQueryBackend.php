<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Read access to the log backend the issues lines are shipped to (Loki, Elasticsearch/OpenSearch, Sentry). The registry
 * job aggregates in the backend — never by reading raw lines, which are capped — and fetches one sample per unknown
 * hash; the CLI and the admin screen read events.
 */
interface IssuesQueryBackend
{
    public function getName(): string;

    /**
     * Error events (error level and above, or error.handled=false) per (group hash, issue hash, entry point) in the range.
     */
    public function aggregateErrors(DateTime $from, DateTime $to): IssueHashCounts;

    public function searchEvents(IssueEventSearch $issueEventSearch): IssueEvents;

    /**
     * Error events (the same filter as {@see self::aggregateErrors()}) per bucket of `$stepSeconds`, gap-free
     * ({@see IssueTrend::fromCounts()}); with a group hash only that group's.
     */
    public function trend(DateTime $from, DateTime $to, int $stepSeconds, ?string $groupHash = null): IssueTrend;

    public function probe(): IssuesBackendProbe;
}
