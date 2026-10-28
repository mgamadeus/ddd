<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Issues;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\Query\IssueEvents;
use DDD\Infrastructure\Logging\Query\IssueEventSearch;
use DDD\Infrastructure\Logging\Query\IssueHashCounts;
use DDD\Infrastructure\Logging\Query\IssuesBackendProbe;
use DDD\Infrastructure\Logging\Query\IssuesQueryBackend;
use RuntimeException;

/** A backend that is down */
class FailingIssuesQueryBackend implements IssuesQueryBackend
{
    public function getName(): string
    {
        return 'failing';
    }

    public function aggregateErrors(DateTime $from, DateTime $to): IssueHashCounts
    {
        throw new RuntimeException('backend down');
    }

    public function searchEvents(IssueEventSearch $issueEventSearch): IssueEvents
    {
        throw new RuntimeException('backend down');
    }

    public function probe(): IssuesBackendProbe
    {
        return new IssuesBackendProbe($this->getName(), false, 'backend down');
    }
}
