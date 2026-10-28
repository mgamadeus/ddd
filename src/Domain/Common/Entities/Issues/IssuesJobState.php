<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Repo\DB\Issues\DBIssuesJobState;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The registry job's own state, one row: its counting watermark, its last runs (the job watches itself — a stalled job
 * or a silent collector must never look like "no errors"), the start of seed mode and the last explosion alert.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBIssuesJobState::class)]
class IssuesJobState extends Entity
{
    use ChangeHistoryTrait;

    /** The state of the single job before environments; one environment adopts it */
    public const string DEFAULT_NAME = 'default';

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_VARCHAR, varCharLength: 64)]
    public string $name = self::DEFAULT_NAME;

    /** @var DateTime|null Events up to here are counted */
    public ?DateTime $watermark = null;

    /** @var DateTime|null First run in this database: seed mode lasts ISSUES_SEED_HOURS from here */
    public ?DateTime $activatedAt = null;

    /** @var DateTime|null Seed mode until (set on demand, e.g. `app:issues:sync --seed=24`) */
    public ?DateTime $seedUntil = null;

    public ?DateTime $lastRunStartedAt = null;

    public ?DateTime $lastSuccessfulRunAt = null;

    /** @var DateTime|null Timestamp of the newest event the job saw in the backend */
    public ?DateTime $newestEventSeenAt = null;

    /** @var string|null Error of the last failed run */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    public ?string $lastError = null;

    /** @var DateTime|null Last "fingerprint explosion" alert */
    public ?DateTime $explosionAlertSentAt = null;
}
