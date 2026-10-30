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
use DDD\Domain\Common\Repo\DB\Issues\DBIssue;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One issue of a root-cause group: the group's bug reached through one entry point (`error.issue_hash`). Informational —
 * a new issue of a known group never alerts.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBIssue::class)]
#[QueryOptions]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE, indexColumns: ['environment', 'issueHash'])]
class Issue extends Entity
{
    use QueryOptionsTrait, ChangeHistoryTrait;

    /** @var string The issue hash (`error.issue_hash`) */
    #[DatabaseIndex]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_VARCHAR, varCharLength: 64)]
    public string $issueHash;

    /** @var string|null The registry environment, the group's; null only on rows from before environments */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_VARCHAR, varCharLength: 64)]
    public ?string $environment = null;

    /** @var int The id of the root-cause group */
    public int $issueGroupId;

    /** @var IssueGroup The root-cause group */
    #[LazyLoad(addAsParent: true)]
    public IssueGroup $issueGroup;

    /** @var string|null Route, CLI:command or MessageHandler:transport */
    public ?string $entryPoint = null;

    public ?DateTime $firstSeenAt = null;

    public ?DateTime $lastSeenAt = null;

    public int $eventCount = 0;
}
