<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Infrastructure\Base\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** A runner told to stop closes the execution it runs instead of leaving it RUNNING */
class CronRunnerStopTest extends TestCase
{
    protected const int SIGNAL_TERM = 15;

    protected function setUp(): void
    {
        // the suite runs without an app config; entities need the default repo type an app's env provides
        LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
    }

    protected function createRunningExecution(): CronExecution
    {
        $cronExecution = new CronExecution();
        $cronExecution->cronId = 7;
        $cronExecution->executionStartedAt = new DateTime();
        $cronExecution->state = CronExecution::STATE_RUNNING;
        return $cronExecution;
    }

    protected function createCron(): RunnerStopProbeCron
    {
        $runnerStopProbeCron = new RunnerStopProbeCron();
        $runnerStopProbeCron->id = 7;
        $runnerStopProbeCron->name = 'every-minute';
        $runnerStopProbeCron->schedule = '* * * * *';
        $runnerStopProbeCron->command = 'app:noop';
        return $runnerStopProbeCron;
    }

    public function testWithoutACommandInProgressNothingIsClosed(): void
    {
        $this->assertNull(Cron::closeCronExecutionInProgressOnRunnerStop(self::SIGNAL_TERM));
    }

    public function testTheRunningExecutionIsClosedAsFailedWithTheSignalAndTheCronMovesOn(): void
    {
        $runnerStopProbeCron = $this->createCron();
        $runnerStopProbeCron->putInProgress($this->createRunningExecution(), null);

        $closedCronExecution = Cron::closeCronExecutionInProgressOnRunnerStop(self::SIGNAL_TERM);

        $this->assertNotNull($closedCronExecution);
        $this->assertCount(1, $runnerStopProbeCron->persistedCronExecutions);
        $persistedCronExecution = $runnerStopProbeCron->persistedCronExecutions[0];
        $this->assertSame(CronExecution::STATE_ENDED, $persistedCronExecution->state);
        $this->assertSame(CronExecution::EXECUTION_STATE_FAILED, $persistedCronExecution->executionState);
        $this->assertStringContainsString('signal 15', $persistedCronExecution->output);
        $this->assertTrue(isset($persistedCronExecution->executionEndedAt));
        $this->assertSame(['nextExecutionScheduledAt'], $runnerStopProbeCron->persistedCronColumns);
        // closed once: a second signal finds nothing in progress
        $this->assertNull(Cron::closeCronExecutionInProgressOnRunnerStop(self::SIGNAL_TERM));
    }

    public function testTheRunningCommandIsStoppedAndItsOutputKept(): void
    {
        $process = new Process(['php', '-r', 'echo "half done"; sleep(30);']);
        $process->start();
        usleep(300000);
        $runnerStopProbeCron = $this->createCron();
        $runnerStopProbeCron->putInProgress($this->createRunningExecution(), $process);

        $closedCronExecution = Cron::closeCronExecutionInProgressOnRunnerStop(self::SIGNAL_TERM);

        $this->assertFalse($process->isRunning());
        $this->assertStringContainsString('half done', $closedCronExecution->output);
        $this->assertStringContainsString('[cron executor]', $closedCronExecution->output);
    }

    public function testAnExecutionThatEndsNormallyIsNoLongerInProgress(): void
    {
        $runnerStopProbeCron = $this->createCron();
        $runnerStopProbeCron->nextExecutionScheduledAt = (new DateTime())->modify('-1 minute');

        $cronExecution = $runnerStopProbeCron->execute(10);

        $this->assertSame(CronExecution::EXECUTION_STATE_SUCCESSFUL, $cronExecution->executionState);

        $this->assertNull(Cron::closeCronExecutionInProgressOnRunnerStop(self::SIGNAL_TERM));
    }
}
