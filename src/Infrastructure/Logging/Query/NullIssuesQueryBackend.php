<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;

/**
 * No query backend: nothing to aggregate or read — the application only logs.
 */
class NullIssuesQueryBackend implements IssuesQueryBackend
{
    public function getName(): string
    {
        return IssuesRegistrySettings::BACKEND_NONE;
    }

    public function aggregateErrors(DateTime $from, DateTime $to): IssueHashCounts
    {
        return new IssueHashCounts();
    }

    public function searchEvents(IssueEventSearch $issueEventSearch): IssueEvents
    {
        return new IssueEvents();
    }

    public function trend(TimeInterval $interval, int $stepSeconds, ?string $groupHash = null): DateTimeValueSequence
    {
        return DateTimeValueSequence::fromValuesByTimestamp($groupHash ?? 'errors', $interval->startDate, $interval->endDate, $stepSeconds, []);
    }

    public function probe(): IssuesBackendProbe
    {
        return new IssuesBackendProbe($this->getName(), false, 'ISSUES_QUERY_BACKEND is not set');
    }
}
