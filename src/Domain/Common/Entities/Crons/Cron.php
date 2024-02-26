<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use Cron\CronExpression;
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\Lazyload\LazyLoad;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Repo\DB\Crons\DBCron;
use DDD\Domain\Common\Services\CronsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\AppService;
use Exception;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @method Crons getParent()
 * @property Crons $parent
 * @method static CronsService getService()
 * @method static DBCron getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBCron::class)]
#[QueryOptions]
#[RolesRequiredForUpdate(Role::ADMIN)]
class Cron extends Entity
{
    use QueryOptionsTrait, ChangeHistoryTrait;

    /** @var string The Cron's name */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    public string $name;

    /** @var string The Cron's descripotion */
    #[NotBlank]
    #[Length(min: 16)]
    public string $description;

    /**
     * @var string The Cron's schedule as cron expression
     * see https://en.wikipedia.org/wiki/Cron
     */
    #[CronExpressionConstraint]
    public string $schedule;

    /** @var string The Symfony CLI command to be executed */
    #[CronCommandConstraint]
    public string $command;

    /** @var DateTime The last start time of Cron execution */
    public DateTime $lastExecutionStartedAt;

    /** @var DateTime The next moment the Cron is scheduled to be executed */
    public DateTime $nextExecutionScheduledAt;

    /** @var CronExecutions The CronExecutopns for current cron */
    #[LazyLoad(LazyLoadRepo::DB)]
    public CronExecutions $executions;

    /**
     * Returns next DateTime when Cron shall be executed
     * @return DateTime
     * @throws Exception
     */
    public function getNextScheduledExecution(): DateTime
    {
        if (isset($this->nextExecutionScheduledAt) && $this->nextExecutionScheduledAt > new DateTime()) {
            return $this->nextExecutionScheduledAt;
        }
        $cronExpression = new CronExpression($this->schedule);
        $nextRunDate = DateTime::fromTimestamp($cronExpression->getNextRunDate()->getTimestamp());
        return $nextRunDate;
    }

    /**
     * Execute the Crons command and stores the results of the Cron execution in a new CronExecution,
     * and returns CronExecution if Cron is scheduled for execution of no other Execution is already running
     * @return CronExecution|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function execute(): ?CronExecution
    {
        $lastExecution = CronExecution::getService()->getLastExecutionForCron($this);
        // we make sure the that this cron is not running currently already and that it is not scheduled in the future
        if ($lastExecution && $lastExecution->state == CronExecution::STATE_RUNNING || $this->nextExecutionScheduledAt > new DateTime(
            )) {
            return null;
        }
        $cronExecution = new CronExecution();
        $cronExecution->cronId = $this->id;
        $cronExecution->executionStartedAt = new DateTime();
        $cronExecution->state = CronExecution::STATE_RUNNING;
        $cronExecution->update();
        $this->lastExecutionStartedAt = $cronExecution->executionStartedAt;
        $this->update();
        $baseCommand = ['php', AppService::instance()->getRootDir() . AppService::instance()->getConsoleDir()];
        $command = array_merge($baseCommand, explode(' ', $this->command));
        $process = new Process($command);
        // we want to capture the output combined
        $process->setInput(Process::ERR | Process::OUT);
        $process->run();
        $cronExecution->executionEndedAt = new DateTime();
        $cronExecution->executionState = $process->isSuccessful(
        ) ? CronExecution::EXECUTION_STATE_SUCCESSFUL : CronExecution::EXECUTION_STATE_FAILED;
        $cronExecution->output = $process->getOutput();
        $cronExecution->state = CronExecution::STATE_ENDED;
        $cronExecution->update();
        $this->nextExecutionScheduledAt = $this->getNextScheduledExecution();
        $this->update();
        $cronExecution->cron = $this;
        return $cronExecution;
    }
}
