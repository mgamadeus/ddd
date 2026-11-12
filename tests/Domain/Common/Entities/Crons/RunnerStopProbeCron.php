<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Crons;

use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use Symfony\Component\Process\Process;
use Throwable;

/** A Cron that records its writes in memory and can be put "in progress" the way Cron::execute() does */
class RunnerStopProbeCron extends Cron
{
    /** @var CronExecution[] The execution rows written, in order */
    public array $persistedCronExecutions = [];

    /** @var string[] The Cron columns written */
    public array $persistedCronColumns = [];

    /** @var Throwable[] */
    public array $loggedFailures = [];

    public function putInProgress(CronExecution $cronExecution, ?Process $process): void
    {
        static::$cronInProgress = $this;
        static::$cronExecutionInProgress = $cronExecution;
        static::$cronProcessInProgress = $process;
    }

    protected function isCommandRegistered(): bool
    {
        return true;
    }

    protected function getLastExecutionOfThisCron(): ?CronExecution
    {
        return null;
    }

    protected function createCronCommandProcess(int $timeoutInSeconds): Process
    {
        $process = new Process(['php', '-r', 'echo "ok";']);
        $process->setTimeout($timeoutInSeconds);
        return $process;
    }

    protected function persistCronExecution(CronExecution $cronExecution): void
    {
        $this->persistedCronExecutions[] = clone $cronExecution;
    }

    protected function persistCronColumns(string ...$propertyNames): void
    {
        array_push($this->persistedCronColumns, ...$propertyNames);
    }

    protected function logCronFailure(Throwable $throwable, array $additionalContext = []): void
    {
        $this->loggedFailures[] = $throwable;
    }
}
