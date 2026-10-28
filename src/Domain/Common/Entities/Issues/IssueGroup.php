<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Repo\DB\Issues\DBIssueGroup;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One root cause in the issues registry: every logged error with this `error.group_hash`. Filled by the registry job
 * from the log backend (never on the request path); its status is set by an admin. The job alerts once when it creates
 * a group (new) and when an event after `resolvedAt` reopens a resolved one (regression).
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBIssueGroup::class)]
#[QueryOptions]
class IssueGroup extends Entity
{
    use QueryOptionsTrait, ChangeHistoryTrait;

    public const string STATUS_OPEN = 'OPEN';
    public const string STATUS_RESOLVED = 'RESOLVED';
    public const string STATUS_IGNORED = 'IGNORED';
    public const array STATUSES = [self::STATUS_OPEN, self::STATUS_RESOLVED, self::STATUS_IGNORED];

    /** @var string The root-cause hash (`error.group_hash`) */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_VARCHAR, varCharLength: 64)]
    public string $groupHash;

    /** @var string OPEN, RESOLVED or IGNORED */
    #[Choice([self::STATUS_OPEN, self::STATUS_RESOLVED, self::STATUS_IGNORED])]
    #[DatabaseIndex]
    public string $status = self::STATUS_OPEN;

    /** @var string|null Exception class of the sample event */
    public ?string $exceptionType = null;

    /** @var string|null Message of the sample event (sanitised by the log) */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    public ?string $sampleMessage = null;

    /** @var string|null Readable slug of the sample event (`error.fingerprint_short`) */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    public ?string $slug = null;

    /** @var string|null `file:line` of the sample event, display only */
    public ?string $logOrigin = null;

    /** @var string|null HTTP, CLI or MESSENGER */
    public ?string $section = null;

    /** @var string|null `service.environment` of the sample event */
    public ?string $environment = null;

    /** @var string|null `event.id` of the sample event */
    public ?string $sampleEventId = null;

    /** @var DateTime|null First event in the backend */
    public ?DateTime $firstSeenAt = null;

    /** @var DateTime|null Latest event counted */
    public ?DateTime $lastSeenAt = null;

    /** @var DateTime|null When the job created the group (the explosion guard counts these) */
    #[DatabaseIndex]
    public ?DateTime $registeredAt = null;

    /** @var int Events counted (approximate under collector replays) */
    public int $eventCount = 0;

    /** @var string|null `service.version` of the first sample */
    public ?string $releaseFirstSeen = null;

    /** @var string|null `service.version` of the latest sample */
    public ?string $releaseLastSeen = null;

    /** @var IssueEntryPoints|null Entry points (route / CLI:command / MessageHandler:transport) the group was reached through */
    public ?IssueEntryPoints $entryPoints = null;

    /** @var DateTime|null Set when resolved; only events after it reopen the group */
    public ?DateTime $resolvedAt = null;

    /** @var bool The job created it as a new root cause (not in seed mode) */
    public bool $isNew = false;

    /** @var bool The job reopened it after it was resolved */
    public bool $isRegression = false;

    /** @var bool An alert is due and not sent yet (retried on the next run) */
    public bool $alertPending = false;

    /** @var DateTime|null When the last alert of this group was sent */
    public ?DateTime $alertSentAt = null;

    /** @var Issues The group's issues: one per issue hash (entry point and application frames) */
    #[LazyLoad]
    public Issues $issues;

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function addEntryPoint(?string $entryPointName): void
    {
        if ($entryPointName === null || $entryPointName === '') {
            return;
        }
        $this->entryPoints ??= new IssueEntryPoints();
        $entryPoint = new IssueEntryPoint($entryPointName);
        $this->entryPoints->add($entryPoint);
    }
}
