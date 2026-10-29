<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Domain\Common\Entities\Crons\CronFailedException;
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

    public bool $commandRegistered = true;

    /** @var array<string, Throwable> Test seam: a column write that fails, by property name */
    public array $failingColumnWrites = [];

    public ?Throwable $throwOnClosingExecution = null;

    /** @var array<int, array{0: Throwable, 1: array<string, mixed>}> Test record: what reached the issues log */
    public array $loggedFailures = [];

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
        if ($cronExecution->state === CronExecution::STATE_ENDED && $this->throwOnClosingExecution !== null) {
            throw $this->throwOnClosingExecution;
        }
        $this->writes[] = 'execution:' . $cronExecution->state;
    }

    protected function isCommandRegistered(): bool
    {
        return $this->commandRegistered;
    }

    protected function logCronFailure(Throwable $throwable, array $additionalContext = []): void
    {
        $this->loggedFailures[] = [$throwable, $additionalContext];
    }

    protected function persistCronColumns(string ...$propertyNames): void
    {
        foreach ($propertyNames as $propertyName) {
            if (isset($this->failingColumnWrites[$propertyName])) {
                throw $this->failingColumnWrites[$propertyName];
            }
        }
        $this->writes[] = 'cron:' . implode(',', $propertyNames);
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
        // distinct ids: a Crons set holds one Cron per id
        $cron->id = crc32($name);
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
        $this->assertSame(['execution:RUNNING', 'cron:lastExecutionStartedAt', 'execution:ENDED', 'cron:nextExecutionScheduledAt'], $cron->writes);
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

    public function testTwoDueCronsTheFirstsCommandThrowsTheSecondRuns(): void
    {
        $brokenCron = $this->dueCron('broken');
        $brokenCron->throwOnProcessCreation = new RuntimeException('console path unreadable');
        $healthyCron = $this->dueCron('healthy');
        $crons = new RecordingCrons();
        $crons->add($brokenCron, $healthyCron);

        $cronExecutions = $crons->execute(5);

        $this->assertSame(['execution:RUNNING', 'cron:lastExecutionStartedAt', 'execution:ENDED', 'cron:nextExecutionScheduledAt'], $healthyCron->writes, 'the second Cron ran');
        $this->assertCount(1, $brokenCron->loggedFailures);
        $this->assertSame('console path unreadable', $brokenCron->loggedFailures[0][0]->getMessage());
        $this->assertSame([], $healthyCron->loggedFailures);
        $this->assertSame([], $crons->loggedFailures, 'the Cron handled its failure itself');
    }

    public function testANonZeroExitIsOneErrorWithExitCodeAndOutputTail(): void
    {
        $cron = $this->dueCron();
        $cron->phpCodeToRun = 'echo str_repeat("x", 3000); fwrite(STDERR, "SQLSTATE[42S02] table missing"); exit(3);';

        $cronExecution = $cron->execute(5);

        $this->assertSame(CronExecution::STATE_ENDED, $cronExecution->state);
        $this->assertSame(CronExecution::EXECUTION_STATE_FAILED, $cronExecution->executionState);
        $this->assertCount(1, $cron->loggedFailures);
        [$throwable, $context] = $cron->loggedFailures[0];
        $this->assertInstanceOf(CronFailedException::class, $throwable);
        $this->assertSame(CronFailedException::KIND_EXIT_CODE, $throwable->kind);
        $this->assertStringContainsString('failed with exit code 3', $throwable->getMessage());
        $this->assertSame(3, $context['exitCode']);
        $this->assertFalse($context['timedOut']);
        $this->assertSame(Cron::LOGGED_OUTPUT_TAIL_LENGTH, mb_strlen($context['outputTail']));
        $this->assertStringEndsWith('SQLSTATE[42S02] table missing', $context['outputTail']);
    }

    public function testATimeoutIsOneErrorThatSaysTimeout(): void
    {
        $cron = $this->dueCron();
        $cron->phpCodeToRun = 'sleep(10);';

        $cronExecution = $cron->execute(1);

        $this->assertSame(CronExecution::EXECUTION_STATE_FAILED, $cronExecution->executionState);
        [$throwable, $context] = $cron->loggedFailures[0];
        $this->assertSame(CronFailedException::KIND_TIMEOUT, $throwable->kind);
        $this->assertStringContainsString('timed out after 1 s', $throwable->getMessage());
        $this->assertTrue($context['timedOut']);
        $this->assertNull($context['exitCode']);
    }

    public function testAnUnregisteredCommandLeavesNoRunningRowAdvancesTheSlotAndLogsOnce(): void
    {
        $staleCron = $this->dueCron('stale');
        $staleCron->commandRegistered = false;
        $nextCron = $this->dueCron('next');
        $crons = new RecordingCrons();
        $crons->add($staleCron, $nextCron);

        $cronExecutions = $crons->execute(5);

        $this->assertSame(['cron:nextExecutionScheduledAt'], $staleCron->writes, 'no execution row, only the next slot');
        $this->assertGreaterThan(time(), $staleCron->nextExecutionScheduledAt->getTimestamp());
        $this->assertCount(1, $staleCron->loggedFailures);
        $this->assertSame(CronFailedException::KIND_COMMAND_NOT_REGISTERED, $staleCron->loggedFailures[0][0]->kind);
        $this->assertStringContainsString('command `app:probe` is not registered', $staleCron->loggedFailures[0][0]->getMessage());
        $this->assertSame([], $crons->loggedFailures, implode(' | ', $crons->loggedFailures));
        $this->assertSame(['execution:RUNNING', 'cron:lastExecutionStartedAt', 'execution:ENDED', 'cron:nextExecutionScheduledAt'], $nextCron->writes, 'the next due Cron ran');
    }

    public function testAFailingBookkeepingWriteStillClosesTheExecution(): void
    {
        $cron = $this->dueCron();
        $cron->failingColumnWrites['lastExecutionStartedAt'] = new RuntimeException('deadlock');

        $cronExecution = $cron->execute(5);

        $this->assertSame(CronExecution::STATE_ENDED, $cronExecution->state);
        $this->assertSame(CronExecution::EXECUTION_STATE_FAILED, $cronExecution->executionState);
        $this->assertStringContainsString('deadlock', $cronExecution->output);
        $this->assertSame(['execution:RUNNING', 'execution:ENDED', 'cron:nextExecutionScheduledAt'], $cron->writes);
        $this->assertSame('deadlock', $cron->loggedFailures[0][0]->getMessage());
    }

    public function testAFailingCloseStillAdvancesTheSlotAndIsReported(): void
    {
        $cron = $this->dueCron();
        $cron->throwOnClosingExecution = new RuntimeException('connection lost');

        try {
            $cron->execute(5);
            $this->fail('the failed close is reported to Crons::execute()');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('connection lost', $runtimeException->getMessage());
        }

        $this->assertSame(['execution:RUNNING', 'cron:lastExecutionStartedAt', 'cron:nextExecutionScheduledAt'], $cron->writes);
    }
}
