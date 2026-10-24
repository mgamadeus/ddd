<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use Cron\CronExpression;
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Repo\DB\Crons\DBCron;
use DDD\Domain\Common\Services\CronsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Exception;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
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

    /** @var bool Active state of Cron */
    public bool $isActive = true;

    /** @var DateTime The last start time of Cron execution */
    public DateTime $lastExecutionStartedAt;

    /** @var DateTime The next moment the Cron is scheduled to be executed */
    public DateTime $nextExecutionScheduledAt;

    /** @var CronExecutions The CronExecutopns for current cron */
    #[LazyLoad(LazyLoadRepo::DB)]
    public CronExecutions $executions;

    /**
     * Execute the Crons command and stores the results of the Cron execution in a new CronExecution,
     * and returns CronExecution if Cron is scheduled for execution of no other Execution is already running.
     *
     * A command that times out or throws never leaves the execution RUNNING: the execution is closed as FAILED with
     * the captured output (stdout and stderr) and the reason, and the next slot is ALWAYS advanced. A RUNNING row
     * excludes the Cron from the tick until the cleanup removes it after hours, so a hanging command would otherwise
     * re-fire only every few hours and hang again; and the exception used to abort every later Cron of the tick.
     *
     * @param int $timeoutPerCronInSeconds
     * @return CronExecution|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws ORMException
     * @throws NonUniqueResultException
     * @throws OptimisticLockException
     */
    public function execute(int $timeoutPerCronInSeconds = 60): ?CronExecution
    {
        $lastExecution = $this->getLastExecutionOfThisCron();
        // we make sure the that this cron is not running currently already and that it is not scheduled in the future
        if (
            $lastExecution && $lastExecution->state == CronExecution::STATE_RUNNING || $this->nextExecutionScheduledAt > new DateTime()
        ) {
            return null;
        }
        $cronExecution = new CronExecution();
        $cronExecution->cronId = $this->id;
        $cronExecution->executionStartedAt = new DateTime();
        $cronExecution->state = CronExecution::STATE_RUNNING;
        $this->persistCronExecution($cronExecution);
        $this->lastExecutionStartedAt = $cronExecution->executionStartedAt;
        $this->persistCron();
        try {
            $process = null;
            $failureMessage = null;
            try {
                $process = $this->createCronCommandProcess($timeoutPerCronInSeconds);
                $process->run();
            } catch (ProcessTimedOutException) {
                $failureMessage = 'Cron command timed out after ' . $timeoutPerCronInSeconds . ' s and was stopped';
            } catch (Throwable $throwable) {
                $failureMessage = 'Cron command failed: ' . get_class($throwable) . ': ' . $throwable->getMessage();
            }
            $cronExecution->executionEndedAt = new DateTime();
            $cronExecution->executionState = $failureMessage === null && $process->isSuccessful()
                ? CronExecution::EXECUTION_STATE_SUCCESSFUL
                : CronExecution::EXECUTION_STATE_FAILED;
            $cronExecution->output = $this->getCombinedProcessOutput($process, $failureMessage);
            $cronExecution->state = CronExecution::STATE_ENDED;
            $this->persistCronExecution($cronExecution);
        } finally {
            // even when closing the execution failed: the Cron must not stay due at a slot that has passed
            $this->nextExecutionScheduledAt = $this->getNextScheduledExecution();
            $this->persistCron();
        }
        $cronExecution->cron = $this;
        return $cronExecution;
    }

    /**
     * The process that runs this Cron's console command, with the timeout set. The seam a test replaces.
     * @param int $timeoutInSeconds
     * @return Process
     */
    protected function createCronCommandProcess(int $timeoutInSeconds): Process
    {
        $baseCommand = ['php', DDDService::instance()->getRootDir() . DDDService::instance()->getConsoleDir()];
        $process = new Process(array_merge($baseCommand, explode(' ', $this->command)));
        $process->setTimeout($timeoutInSeconds);
        return $process;
    }

    /**
     * What the execution stores as output: stdout, then stderr — where a failing command writes its error — then the
     * executor's own reason when the command timed out or could not run.
     * @param Process|null $process
     * @param string|null $failureMessage
     * @return string
     */
    protected function getCombinedProcessOutput(?Process $process, ?string $failureMessage): string
    {
        $outputParts = [];
        if ($process !== null) {
            $outputParts[] = $process->getOutput();
            $outputParts[] = $process->getErrorOutput();
        }
        if ($failureMessage !== null) {
            $outputParts[] = '[cron executor] ' . $failureMessage;
        }
        return trim(implode("\n", array_filter($outputParts, static fn (string $outputPart): bool => trim($outputPart) !== '')));
    }

    /**
     * @return CronExecution|null The latest execution of this Cron — the seam a test replaces
     */
    protected function getLastExecutionOfThisCron(): ?CronExecution
    {
        return CronExecution::getService()->getLastExecutionForCron($this);
    }

    /** Writes the execution row — the seam a test replaces. */
    protected function persistCronExecution(CronExecution $cronExecution): void
    {
        $cronExecution->update();
    }

    /** Writes this Cron row — the seam a test replaces. */
    protected function persistCron(): void
    {
        $this->update();
    }

    /**
     * Switches the Cron ON. A Cron that was off gets its next execution re-anchored through
     * {@see self::getNextScheduledExecution()}: the ticker runs every active Cron whose `nextExecutionScheduledAt` has
     * passed, so a Cron paused across its slot would otherwise fire at the very next tick instead of its next slot. A
     * stored slot that still lies ahead is kept — the expression is unchanged, so it IS the next slot. Not persisted.
     * @return void
     * @throws Exception
     */
    public function activate(): void
    {
        if ($this->isActive) {
            return;
        }
        $this->isActive = true;
        $this->nextExecutionScheduledAt = $this->getNextScheduledExecution();
    }

    /**
     * Switches the Cron OFF; schedule, command and the stored slot stay as they are. Not persisted.
     * @return void
     */
    public function deactivate(): void
    {
        $this->isActive = false;
    }

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
}
