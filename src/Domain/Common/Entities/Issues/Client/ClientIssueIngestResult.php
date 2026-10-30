<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Client;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What {@see \DDD\Infrastructure\Services\IssuesLogService::logClientIssue()} did with a report: events logged, events
 * dropped and why (one reason per dropped event, in report order).
 */
class ClientIssueIngestResult extends ValueObject
{
    /** The ingest is switched off (`clientIngestEnabled` / ISSUES_CLIENT_INGEST_ENABLED) */
    public const string DROP_REASON_INGEST_DISABLED = 'ingest-disabled';
    /** The message matches one of the ignore patterns (chunk loads, `Script error.`, `ResizeObserver loop`, …) */
    public const string DROP_REASON_IGNORED_MESSAGE = 'ignored-message';
    /** The user agent is a crawler */
    public const string DROP_REASON_IGNORED_USER_AGENT = 'ignored-user-agent';
    /** More events than {@see ClientIssueEvents::MAX_EVENTS} in one report */
    public const string DROP_REASON_TOO_MANY_EVENTS = 'too-many-events';

    public int $accepted = 0;

    public int $dropped = 0;

    /** @var string[] One of the DROP_REASON_* constants per dropped event */
    public array $dropReasons = [];

    public function addDroppedEvent(string $dropReason): void
    {
        $this->dropped++;
        $this->dropReasons[] = $dropReason;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
