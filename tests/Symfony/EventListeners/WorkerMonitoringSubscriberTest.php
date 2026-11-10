<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\EventListeners;

use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Domain\Common\Services\WorkerMonitoringService;
use DDD\Symfony\EventListeners\WorkerMonitoringSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleSignalEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;

class WorkerMonitoringSubscriberTest extends TestCase
{
    protected function consumeCommandEvent(array $options): ConsoleCommandEvent
    {
        $inputDefinition = new InputDefinition([
            new InputOption('limit', null, InputOption::VALUE_REQUIRED),
            new InputOption('time-limit', null, InputOption::VALUE_REQUIRED),
            new InputOption('memory-limit', null, InputOption::VALUE_REQUIRED),
            new InputOption('failure-limit', null, InputOption::VALUE_REQUIRED),
        ]);
        return new ConsoleCommandEvent(
            new Command(WorkerMonitoringService::CONSUME_COMMAND_NAME),
            new ArrayInput($options, $inputDefinition),
            new NullOutput()
        );
    }

    public function testTheConsumeLimitsTellTheStopReasonsApart(): void
    {
        $workerMonitoringService = new WorkerMonitoringService();
        $workerMonitoringSubscriber = new WorkerMonitoringSubscriber($workerMonitoringService);

        $workerMonitoringSubscriber->onConsoleCommand($this->consumeCommandEvent(['--time-limit' => '3600', '--memory-limit' => '1G']));
        self::assertSame(WorkerProcess::STOP_REASON_OTHER, $workerMonitoringService->getStopReason(microtime(true)));

        // a memory limit the process is already above
        $workerMonitoringSubscriber->onConsoleCommand($this->consumeCommandEvent(['--memory-limit' => '1K']));
        self::assertSame(WorkerProcess::STOP_REASON_MEMORY_LIMIT, $workerMonitoringService->getStopReason(microtime(true)));
    }

    public function testAStopSignalWinsOverTheLimits(): void
    {
        $workerMonitoringService = new WorkerMonitoringService();
        $workerMonitoringSubscriber = new WorkerMonitoringSubscriber($workerMonitoringService);
        $workerMonitoringSubscriber->onConsoleCommand($this->consumeCommandEvent(['--memory-limit' => '1K']));

        $workerMonitoringSubscriber->onConsoleSignal(
            new ConsoleSignalEvent(new Command('messenger:consume'), new ArrayInput([]), new NullOutput(), 15)
        );

        self::assertSame(WorkerProcess::STOP_REASON_SIGNAL, $workerMonitoringService->getStopReason(microtime(true)));
    }
}
