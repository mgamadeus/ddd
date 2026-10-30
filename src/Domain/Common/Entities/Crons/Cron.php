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
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LogLevel;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Exception;
use ReflectionException;
use Throwable;

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
    /** Characters of a failed run's output kept in the issues log (the end: where the error is) */
    public const int LOGGED_OUTPUT_TAIL_LENGTH = 2000;

    /** An unregistered command is logged at most this often per Cron */
    public const int UNREGISTERED_COMMAND_LOG_INTERVAL_SECONDS = 3600;

    public const string UNREGISTERED_COMMAND_LOG_CACHE_KEY_PREFIX = 'ddd_cron_unregistered_command_';

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
     * Failure isolation — one Cron never blocks itself or the tick, and every failure reaches the issues log (ERROR):
     * - A command this application does not register (a row from another branch, a removed command) is caught BEFORE
     *   any execution row exists: one ERROR (at most hourly per Cron), the next slot advanced, null returned.
     * - Once the RUNNING row is written, every way out closes it — ENDED, FAILED with the reason — and advances the
     *   next slot, also when a bookkeeping write fails.
     * - The bookkeeping stamps (`lastExecutionStartedAt`, `nextExecutionScheduledAt`) are partial writes: they never
     *   re-run the entity validation (whose command check could fail on exactly the Cron being reported).
     * - A failed run (non-zero exit, timeout, a throwable) is logged with name, id, command, exit code, timeout flag and
     *   the output's tail; a failure of the logging itself never leaves this method.
     *
     * @param int $timeoutPerCronInSeconds
     * @return CronExecution|null
     * @throws Throwable Closing the execution row itself failed (after the slot was advanced) — Crons::execute() logs
     *                   it and goes on with the next Cron
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
        if (!$this->isCommandRegistered()) {
            $this->reportUnregisteredCommand();
            $this->advanceNextExecution();
            return null;
        }
        $cronExecution = new CronExecution();
        $cronExecution->cronId = $this->id;
        $cronExecution->executionStartedAt = new DateTime();
        $cronExecution->state = CronExecution::STATE_RUNNING;
        $this->persistCronExecution($cronExecution);

        $process = null;
        $failureMessage = null;
        $failureThrowable = null;
        $timedOut = false;
        $closingFailure = null;
        try {
            $this->lastExecutionStartedAt = $cronExecution->executionStartedAt;
            $this->persistCronColumns('lastExecutionStartedAt');
            try {
                $process = $this->createCronCommandProcess($timeoutPerCronInSeconds);
                $process->run();
            } catch (ProcessTimedOutException) {
                $timedOut = true;
                $failureMessage = 'Cron command timed out after ' . $timeoutPerCronInSeconds . ' s and was stopped';
            }
        } catch (Throwable $throwable) {
            $failureThrowable = $throwable;
            $failureMessage = 'Cron command failed: ' . get_class($throwable) . ': ' . $throwable->getMessage();
        } finally {
            // every way out after the RUNNING row: closed, and the Cron not left due at a slot that has passed
            $cronExecution->executionEndedAt = new DateTime();
            $cronExecution->executionState = $failureMessage === null && $process?->isSuccessful()
                ? CronExecution::EXECUTION_STATE_SUCCESSFUL
                : CronExecution::EXECUTION_STATE_FAILED;
            $cronExecution->output = $this->getCombinedProcessOutput($process, $failureMessage);
            $cronExecution->state = CronExecution::STATE_ENDED;
            try {
                $this->persistCronExecution($cronExecution);
            } catch (Throwable $throwable) {
                $closingFailure = $throwable;
            }
            $this->advanceNextExecution();
        }
        if ($cronExecution->executionState === CronExecution::EXECUTION_STATE_FAILED) {
            $this->reportFailedExecution($cronExecution, $process, $timedOut, $failureThrowable, $timeoutPerCronInSeconds);
        }
        if ($closingFailure !== null) {
            throw $closingFailure;
        }
        $cronExecution->cron = $this;
        return $cronExecution;
    }

    /** The next slot, written as a partial update; a failing write is logged, never thrown */
    protected function advanceNextExecution(): void
    {
        try {
            $this->nextExecutionScheduledAt = $this->getNextScheduledExecution();
            $this->persistCronColumns('nextExecutionScheduledAt');
        } catch (Throwable $throwable) {
            $this->logCronFailure($throwable, ['cronStep' => 'advanceNextExecution']);
        }
    }

    /** Whether this application registers the Cron's command (its first word) — the seam a test replaces */
    protected function isCommandRegistered(): bool
    {
        $commandName = explode(' ', trim((string)($this->command ?? '')), 2)[0];
        if ($commandName === '') {
            return false;
        }
        try {
            return DDDService::instance()->createConsoleApplicationForCurrentKernel()->has($commandName);
        } catch (Throwable) {
            // the application cannot be built here: let the process report it
            return true;
        }
    }

    /** One ERROR for an unregistered command — at most once per hour per Cron, so a stale row does not flood the log */
    protected function reportUnregisteredCommand(): void
    {
        $rateLimitKey = self::UNREGISTERED_COMMAND_LOG_CACHE_KEY_PREFIX . ($this->id ?? $this->name ?? '');
        try {
            $cache = Cache::instance();
            if ($cache->get($rateLimitKey)) {
                return;
            }
            $cache->set($rateLimitKey, true, self::UNREGISTERED_COMMAND_LOG_INTERVAL_SECONDS);
        } catch (Throwable) {
            // no cache: every occurrence is logged
        }
        $this->logCronFailure(new CronFailedException(
            sprintf('Cron %s (%s): command `%s` is not registered in this application', $this->name ?? '?', $this->id ?? '?', $this->command ?? ''),
            (string)($this->name ?? $this->id),
            CronFailedException::KIND_COMMAND_NOT_REGISTERED
        ));
    }

    /** The failed run as an ERROR: the throwable it threw, or a CronFailedException for an exit code / a timeout */
    protected function reportFailedExecution(
        CronExecution $cronExecution,
        ?Process $process,
        bool $timedOut,
        ?Throwable $failureThrowable,
        int $timeoutPerCronInSeconds
    ): void {
        $exitCode = $process !== null && !$timedOut && $failureThrowable === null ? $process->getExitCode() : null;
        $throwable = $failureThrowable ?? new CronFailedException(
            $timedOut
                ? sprintf('Cron %s (%s): command `%s` timed out after %d s', $this->name ?? '?', $this->id ?? '?', $this->command ?? '', $timeoutPerCronInSeconds)
                : sprintf('Cron %s (%s): command `%s` failed with exit code %s', $this->name ?? '?', $this->id ?? '?', $this->command ?? '', $exitCode ?? '?'),
            (string)($this->name ?? $this->id),
            $timedOut ? CronFailedException::KIND_TIMEOUT : CronFailedException::KIND_EXIT_CODE
        );
        $this->logCronFailure($throwable, [
            'exitCode' => $exitCode,
            'timedOut' => $timedOut,
            'outputTail' => mb_substr((string)$cronExecution->output, -self::LOGGED_OUTPUT_TAIL_LENGTH),
        ]);
    }

    /**
     * Into the issues log as ERROR (section CLI) with the Cron's name, id and command — never throws.
     *
     * @param array<string, mixed> $additionalContext
     */
    protected function logCronFailure(Throwable $throwable, array $additionalContext = []): void
    {
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable(
                $throwable,
                LogLevel::ERROR,
                ['cronName' => $this->name ?? null, 'cronId' => $this->id ?? null, 'cronCommand' => $this->command ?? null] + $additionalContext,
                false,
                IssuesLogService::LOG_MESSAGE_SECTION_CLI
            );
        } catch (Throwable) {
            error_log('Cron ' . ($this->name ?? '?') . ' failed: ' . $throwable->getMessage());
        }
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

    /**
     * Writes only these columns of this Cron row, without the entity validation — the bookkeeping stamps; the seam a
     * test replaces.
     */
    protected function persistCronColumns(string ...$propertyNames): void
    {
        $this->updatePartialIgnoringRights(...$propertyNames);
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
