<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\Lazyload\LazyLoad;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Common\Repo\DB\Crons\DBCronExecution;
use DDD\Domain\Common\Services\CronExecutionsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * @method Crons getParent()
 * @property Crons $parent
 * @method static CronExecutionsService getService()
 * @method static DBCronExecution getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBCronExecution::class)]
#[RolesRequiredForUpdate(Role::ADMIN)]
#[QueryOptions]
class CronExecution extends Entity
{
    use QueryOptionsTrait, ChangeHistoryTrait;

    /** @var string Cron is started and running */
    public const STATE_RUNNING = 'RUNNING';

    /** @var string Cron has been executed and ended */
    public const STATE_ENDED = 'ENDED';

    /** @var string Cron has been executed successfully */
    public const EXECUTION_STATE_SUCCESSFUL = 'SUCCESSFUL';

    /** @var string Cron has been executed with errors */
    public const EXECUTION_STATE_FAILED = 'FAILED';

    /** @var int The id of the executed Cron */
    public int $cronId;

    /** @var Cron The executed Cron */
    #[LazyLoad(LazyLoadRepo::DB)]
    public Cron $cron;

    /** @var DateTime The DateTime of the Cron's execution */
    public DateTime $executionStartedAt;

    /** @var DateTime The DateTime of the Cron's execution */
    public DateTime $executionEndedAt;

    /** @var string The running state of the CronExecution */
    #[Choice([self::STATE_RUNNING, self::STATE_ENDED])]
    public string $state;

    /** @var string The execution state of the CronExecution */
    #[Choice([self::EXECUTION_STATE_SUCCESSFUL, self::EXECUTION_STATE_FAILED])]
    public string $executionState;

    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    public string $output;
}
