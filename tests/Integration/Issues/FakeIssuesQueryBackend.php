<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Issues;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\Query\IssueEvent;
use DDD\Infrastructure\Logging\Query\IssueEvents;
use DDD\Infrastructure\Logging\Query\IssueEventSearch;
use DDD\Infrastructure\Logging\Query\IssueHashCount;
use DDD\Infrastructure\Logging\Query\IssueHashCounts;
use DDD\Infrastructure\Logging\Query\IssueTrend;
use DDD\Infrastructure\Logging\Query\IssuesBackendProbe;
use DDD\Infrastructure\Logging\Query\IssuesQueryBackend;

/**
 * In-memory log backend: events with an occurrence count (a storm of 10 000 identical lines is one entry), aggregated
 * like Loki's metric query — error and above only.
 */
class FakeIssuesQueryBackend implements IssuesQueryBackend
{
    public const array ERROR_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    /** @var array<int, array{0: IssueEvent, 1: int}> Method-local test store: event and occurrences */
    protected array $storedEvents = [];

    public function addEvent(
        int $timestamp,
        string $groupHash,
        string $issueHash = '',
        string $entryPoint = 'route:test',
        string $level = 'error',
        int $occurrences = 1,
        ?string $release = 'r1',
        string $message = 'Something failed',
        ?string $workspace = null,
    ): void {
        $this->storedEvents[] = [
            new IssueEvent(
                timestamp: DateTime::fromTimestamp($timestamp),
                level: $level,
                message: $message,
                eventId: uniqid('event', true),
                groupHash: $groupHash,
                issueHash: $issueHash !== '' ? $issueHash : $groupHash . '-' . $entryPoint,
                entryPoint: $entryPoint,
                exceptionType: 'RuntimeException',
                slug: 'runtime-exception-' . substr($groupHash, 0, 6),
                environment: 'test',
                release: $release,
                workspace: $workspace,
            ),
            $occurrences,
        ];
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function aggregateErrors(DateTime $from, DateTime $to): IssueHashCounts
    {
        $countsByKey = [];
        foreach ($this->storedEvents as [$issueEvent, $occurrences]) {
            if (!in_array($issueEvent->level, self::ERROR_LEVELS, true) || !$this->isInWindow($issueEvent, $from, $to)) {
                continue;
            }
            $key = $issueEvent->groupHash . '|' . $issueEvent->issueHash . '|' . $issueEvent->entryPoint;
            $countsByKey[$key] = ($countsByKey[$key] ?? 0) + $occurrences;
        }
        $issueHashCounts = new IssueHashCounts();
        foreach ($countsByKey as $key => $count) {
            [$groupHash, $issueHash, $entryPoint] = explode('|', $key);
            $issueHashCount = new IssueHashCount($groupHash, $issueHash, $entryPoint, $count);
            $issueHashCounts->add($issueHashCount);
        }
        return $issueHashCounts;
    }

    public function searchEvents(IssueEventSearch $issueEventSearch): IssueEvents
    {
        $matchingEvents = [];
        foreach ($this->storedEvents as [$issueEvent]) {
            if (!$this->isInWindow($issueEvent, $issueEventSearch->from, $issueEventSearch->to)
                || ($issueEventSearch->groupHash !== null && $issueEvent->groupHash !== $issueEventSearch->groupHash)
                || ($issueEventSearch->issueHash !== null && $issueEvent->issueHash !== $issueEventSearch->issueHash)
                || ($issueEventSearch->workspace !== null && $issueEvent->workspace !== $issueEventSearch->workspace)) {
                continue;
            }
            $matchingEvents[] = $issueEvent;
        }
        usort($matchingEvents, static fn(IssueEvent $left, IssueEvent $right): int => $issueEventSearch->oldestFirst
            ? $left->timestamp->getTimestamp() <=> $right->timestamp->getTimestamp()
            : $right->timestamp->getTimestamp() <=> $left->timestamp->getTimestamp());
        $issueEvents = new IssueEvents();
        foreach (array_slice($matchingEvents, 0, $issueEventSearch->limit) as $issueEvent) {
            $issueEvents->add($issueEvent);
        }
        return $issueEvents;
    }

    public function trend(DateTime $from, DateTime $to, int $stepSeconds, ?string $groupHash = null): IssueTrend
    {
        $countsByTimestamp = [];
        foreach ($this->storedEvents as [$issueEvent, $occurrences]) {
            if (!in_array($issueEvent->level, self::ERROR_LEVELS, true) || !$this->isInWindow($issueEvent, $from, $to)
                || ($groupHash !== null && $issueEvent->groupHash !== $groupHash)) {
                continue;
            }
            $timestamp = $issueEvent->timestamp->getTimestamp();
            $countsByTimestamp[$timestamp] = ($countsByTimestamp[$timestamp] ?? 0) + $occurrences;
        }
        return IssueTrend::fromCounts($from, $to, $stepSeconds, $countsByTimestamp);
    }

    public function probe(): IssuesBackendProbe
    {
        return new IssuesBackendProbe($this->getName(), true, 'ok');
    }

    protected function isInWindow(IssueEvent $issueEvent, DateTime $from, DateTime $to): bool
    {
        $timestamp = $issueEvent->timestamp->getTimestamp();
        return $timestamp >= $from->getTimestamp() && $timestamp <= $to->getTimestamp();
    }
}
