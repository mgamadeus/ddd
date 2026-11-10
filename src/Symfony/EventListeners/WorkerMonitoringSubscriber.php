<?php

declare(strict_types=1);

namespace DDD\Symfony\EventListeners;

use DDD\Domain\Common\Services\WorkerMonitoringService;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleSignalEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Throwable;

/**
 * Tells the worker monitoring ({@see WorkerMonitoringService}) what happens to the worker of this process: started,
 * tick (between two messages and while idle), stopped, a message failed, a stop signal arrived — and the limits the
 * consume command runs with, which tell the stop reasons apart. A restart requested for the process is answered at
 * the tick by stopping the worker; whatever runs the workers starts it again.
 *
 * Fail-soft: the monitoring never stops a worker or a command by an error of its own.
 */
class WorkerMonitoringSubscriber implements EventSubscriberInterface
{
    /** Signals that stop a worker */
    public const array STOP_SIGNALS = [2, 3, 15];
    public const string OPTION_MESSAGE_LIMIT = 'limit';
    public const string OPTION_TIME_LIMIT = 'time-limit';
    public const string OPTION_MEMORY_LIMIT = 'memory-limit';
    public const string OPTION_FAILURE_LIMIT = 'failure-limit';

    public function __construct(protected WorkerMonitoringService $workerMonitoringService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onConsoleCommand',
            ConsoleEvents::SIGNAL => 'onConsoleSignal',
            ConsoleEvents::TERMINATE => 'onConsoleTerminate',
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerStoppedEvent::class => 'onWorkerStopped',
            // after the retry listener decided: it runs at priority 100
            WorkerMessageFailedEvent::class => ['onWorkerMessageFailed', -100],
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $consoleCommandEvent): void
    {
        try {
            if ($consoleCommandEvent->getCommand()?->getName() !== WorkerMonitoringService::CONSUME_COMMAND_NAME) {
                return;
            }
            $input = $consoleCommandEvent->getInput();
            $this->workerMonitoringService->setConsumeLimits(
                $this->getIntOption($input, self::OPTION_MESSAGE_LIMIT),
                $this->getIntOption($input, self::OPTION_TIME_LIMIT),
                $this->getMemoryLimitInBytes($input),
                $this->getIntOption($input, self::OPTION_FAILURE_LIMIT)
            );
        } catch (Throwable) {
            // fail-soft, see the class docblock
        }
    }

    public function onConsoleSignal(ConsoleSignalEvent $consoleSignalEvent): void
    {
        if (in_array($consoleSignalEvent->getHandlingSignal(), self::STOP_SIGNALS, true)) {
            $this->workerMonitoringService->reportSignal();
        }
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $consoleTerminateEvent): void
    {
        try {
            $this->workerMonitoringService->flushAfterCommandLineRun();
        } catch (Throwable) {
            // fail-soft, see the class docblock
        }
    }

    public function onWorkerStarted(WorkerStartedEvent $workerStartedEvent): void
    {
        try {
            $this->workerMonitoringService->registerWorker($workerStartedEvent->getWorker()->getMetadata()->getTransportNames());
        } catch (Throwable) {
            // fail-soft, see the class docblock
        }
    }

    public function onWorkerRunning(WorkerRunningEvent $workerRunningEvent): void
    {
        try {
            $restartWasRequested = $this->workerMonitoringService->tick();
        } catch (Throwable) {
            return;
        }
        if ($restartWasRequested) {
            $workerRunningEvent->getWorker()->stop();
        }
    }

    public function onWorkerStopped(WorkerStoppedEvent $workerStoppedEvent): void
    {
        try {
            $this->workerMonitoringService->closeWorker();
        } catch (Throwable) {
            // fail-soft, see the class docblock
        }
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $workerMessageFailedEvent): void
    {
        try {
            $this->workerMonitoringService->reportWillRetryOfLastMessage($workerMessageFailedEvent->willRetry());
        } catch (Throwable) {
            // fail-soft, see the class docblock
        }
    }

    protected function getIntOption(InputInterface $input, string $optionName): ?int
    {
        if (!$input->hasOption($optionName)) {
            return null;
        }
        $optionValue = $input->getOption($optionName);
        return is_numeric($optionValue) && (int)$optionValue > 0 ? (int)$optionValue : null;
    }

    /** @return int|null The consume command's memory limit ("128M", "1G", a number of bytes) in bytes */
    protected function getMemoryLimitInBytes(InputInterface $input): ?int
    {
        if (!$input->hasOption(self::OPTION_MEMORY_LIMIT)) {
            return null;
        }
        $memoryLimit = strtolower(trim((string)$input->getOption(self::OPTION_MEMORY_LIMIT)));
        if ($memoryLimit === '' || !preg_match('/^(\d+)([kmg]?)b?$/', $memoryLimit, $matches)) {
            return null;
        }
        $bytes = (int)$matches[1];
        return match ($matches[2]) {
            'g' => $bytes * 1024 ** 3,
            'm' => $bytes * 1024 ** 2,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }
}
