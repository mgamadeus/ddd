<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Infrastructure\Base\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** A Cron whose command process and persistence run in memory — no console, no database. */
class InMemoryCron extends Cron
{
    /** @var string|null The PHP code the "command" runs in a child process */
    public ?string $phpCodeToRun = null;

    public ?Throwable $throwOnProcessCreation = null;

    /** @var string[] What was written, in order: 'execution:RUNNING', 'cron', 'execution:ENDED', … */
    public array $writes = [];

    protected function createCronCommandProcess(int $timeoutInSeconds): Process
    {
        if ($this->throwOnProcessCreation !== null) {
            throw $this->throwOnProcessCreation;
        }
        $process = new Process(['php', '-r', $this->phpCodeToRun ?? 'echo "ok";']);
        $process->setTimeout($timeoutInSeconds);
        return $process;
    }

    protected function getLastExecutionOfThisCron(): ?CronExecution
    {
        return null;
    }

    protected function persistCronExecution(CronExecution $cronExecution): void
    {
        $this->writes[] = 'execution:' . $cronExecution->state;
    }

    protected function persistCron(): void
    {
        $this->writes[] = 'cron';
    }
}

/** A Cron whose execution throws outright, as when its bookkeeping write fails. */
class ThrowingCron extends Cron
{
    public function execute(int $timeoutPerCronInSeconds = 60): ?CronExecution
    {
        throw new RuntimeException('database went away');
    }
}

/** Records instead of logging to the issues log. */
class RecordingCrons extends Crons
{
    /** @var string[] */
    public array $loggedFailures = [];

    protected function logCronExecutionFailure(Cron $cron, Throwable $throwable): void
    {
        $this->loggedFailures[] = $cron->name . ': ' . $throwable->getMessage();
    }
}

/**
 * A command that hangs or a write that fails must neither leave a RUNNING execution behind, nor keep the Cron at a
 * slot that has passed, nor cost the other due Crons of the tick their run.
 */
class CronExecuteFailureHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        // the suite runs without an app config; entities need the default repo type an app's env provides
        LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
    }

    protected function dueCron(string $name = 'probe'): InMemoryCron
    {
        $cron = new InMemoryCron();
        $cron->id = 1;
        $cron->name = $name;
        $cron->schedule = '*/5 * * * *';
        $cron->command = 'app:probe';
        $cron->isActive = true;
        $cron->nextExecutionScheduledAt = DateTime::fromTimestamp(time() - 60);
        return $cron;
    }

    public function testATimedOutCommandClosesTheExecutionAsFailedAndAdvancesTheSlot(): void
    {
        $cron = $this->dueCron();
        $cron->phpCodeToRun = 'echo "started"; fwrite(STDERR, "working"); sleep(10);';

        $cronExecution = $cron->execute(1);

        $this->assertSame(CronExecution::STATE_ENDED, $cronExecution->state);
        $this->assertSame(CronExecution::EXECUTION_STATE_FAILED, $cronExecution->executionState);
        $this->assertStringContainsString('timed out after 1 s', $cronExecution->output);
        $this->assertStringContainsString('started', $cronExecution->output, 'output captured until the timeout is kept');
        $this->assertGreaterThan(time(), $cron->nextExecutionScheduledAt->getTimestamp());
        $this->assertSame(['execution:RUNNING', 'cron', 'execution:ENDED', 'cron'], $cron->writes);
    }

    public function testACommandThatCannotStartClosesTheExecutionAsFailedAndAdvancesTheSlot(): void
    {
        $cron = $this->dueCron();
        $cron->throwOnProcessCreation = new RuntimeException('console path unreadable');

        $cronExecution = $cron->execute(5);

        $this->assertSame(CronExecution::EXECUTION_STATE_FAILED, $cronExecution->executionState);
        $this->assertStringContainsString('RuntimeException: console path unreadable', $cronExecution->output);
        $this->assertGreaterThan(time(), $cron->nextExecutionScheduledAt->getTimestamp());
    }

    /** stderr is where a failing command writes its error; it used to be dropped. */
    public function testAFailingCommandKeepsItsErrorOutput(): void
    {
        $cron = $this->dueCron();
        $cron->phpCodeToRun = 'fwrite(STDERR, "SQLSTATE[42S02] table missing"); exit(1);';

        $cronExecution = $cron->execute(5);

        $this->assertSame(CronExecution::EXECUTION_STATE_FAILED, $cronExecution->executionState);
        $this->assertStringContainsString('SQLSTATE[42S02] table missing', $cronExecution->output);
    }

    public function testASuccessfulCommandIsRecordedAsSuccessful(): void
    {
        $cron = $this->dueCron();
        $cron->phpCodeToRun = 'echo "done";';

        $cronExecution = $cron->execute(5);

        $this->assertSame(CronExecution::EXECUTION_STATE_SUCCESSFUL, $cronExecution->executionState);
        $this->assertSame('done', $cronExecution->output);
    }

    public function testAFailingCronDoesNotStopTheOtherCronsOfTheTick(): void
    {
        $throwingCron = new ThrowingCron();
        $throwingCron->name = 'broken';
        $healthyCron = $this->dueCron('healthy');
        $crons = new RecordingCrons();
        $crons->add($throwingCron, $healthyCron);

        $cronExecutions = $crons->execute(5);

        $this->assertSame(1, $cronExecutions->count(), 'the healthy Cron after the broken one still ran');
        $this->assertSame(['broken: database went away'], $crons->loggedFailures);
    }
}
