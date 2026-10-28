<?php

declare(strict_types=1);

namespace DDD\Symfony\EventListeners;

use DDD\Infrastructure\Exceptions\Exception as DDDException;
use DDD\Infrastructure\Services\IssuesLogService;
use ErrorException;
use Psr\Log\LogLevel;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Throwable;

/**
 * Logs every error that reaches the framework into the issues channel — once ({@see IssuesLogService::wasLogged()}):
 *
 * - uncaught HTTP exceptions (priority 100, before {@see ExceptionListener} renders DDD exceptions and stops the event):
 *   the status is the DDD exception's code, else the HttpException's status, else 500; ≥ 500 is logged CRITICAL with
 *   `error.handled=false`, 4xx only when `ISSUES_LOG_4XX_LEVEL` is set;
 * - console errors (CRITICAL) and failed messenger messages (CRITICAL);
 * - PHP fatal errors, through a shutdown handler registered with the first request or command.
 *
 * `ISSUES_CAPTURE_ENABLED=0` switches all of it off, for an application that still runs its own capture.
 */
class IssuesCaptureSubscriber implements EventSubscriberInterface
{
    /** PHP error types that end the process */
    public const array FATAL_ERROR_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    protected static bool $shutdownHandlerRegistered = false;

    public function __construct(protected IssuesLogService $issuesLogService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 2048],
            KernelEvents::EXCEPTION => ['onKernelException', 100],
            ConsoleEvents::COMMAND => ['onConsoleCommand', 2048],
            ConsoleEvents::ERROR => 'onConsoleError',
            WorkerMessageFailedEvent::class => 'onWorkerMessageFailed',
        ];
    }

    protected function isEnabled(): bool
    {
        return IssuesLogService::getSettings()->captureEnabled;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->registerShutdownHandler(IssuesLogService::LOG_MESSAGE_SECTION_HTTP);
        }
    }

    public function onConsoleCommand(): void
    {
        $this->registerShutdownHandler(IssuesLogService::LOG_MESSAGE_SECTION_CLI);
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $throwable = $event->getThrowable();
        $status = $this->getHttpStatus($throwable);
        if ($status >= 500) {
            $level = LogLevel::CRITICAL;
        } elseif (($clientErrorLevel = IssuesLogService::getSettings()->clientErrorLevel) !== null) {
            $level = $clientErrorLevel;
        } else {
            return;
        }
        $this->issuesLogService->logThrowable(
            $throwable,
            $level,
            ['http_status' => $status, 'error.handled' => false],
            section: IssuesLogService::LOG_MESSAGE_SECTION_HTTP
        );
    }

    /** The HTTP status the throwable answers with: a DDD exception's code, an HttpException's status, else 500 */
    public function getHttpStatus(Throwable $throwable): int
    {
        if ($throwable instanceof DDDException && $throwable->getCode() >= 400 && $throwable->getCode() < 600) {
            return (int)$throwable->getCode();
        }
        if ($throwable instanceof HttpExceptionInterface) {
            return $throwable->getStatusCode();
        }
        return 500;
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $this->issuesLogService->logThrowable(
            $event->getError(),
            LogLevel::CRITICAL,
            [
                'command' => $event->getCommand()?->getName(),
                'exit_code' => $event->getExitCode(),
                'error.handled' => false,
            ],
            section: IssuesLogService::LOG_MESSAGE_SECTION_CLI
        );
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $envelope = $event->getEnvelope();
        $transportNames = $envelope->last(TransportNamesStamp::class)?->getTransportNames() ?? [$event->getReceiverName()];
        $this->issuesLogService->logThrowable(
            $event->getThrowable(),
            LogLevel::CRITICAL,
            array_filter([
                'messenger.message_class' => get_debug_type($envelope->getMessage()),
                'transports' => $transportNames,
                'will_retry' => $event->willRetry(),
                'error.handled' => false,
            ], static fn(mixed $value): bool => $value !== null),
            section: IssuesLogService::LOG_MESSAGE_SECTION_MESSENGER
        );
    }

    /** Registers, once per process, the handler that logs a PHP fatal error (it never reaches an event) */
    protected function registerShutdownHandler(string $section): void
    {
        if (static::$shutdownHandlerRegistered || !$this->isEnabled()) {
            return;
        }
        static::$shutdownHandlerRegistered = true;
        $issuesLogService = $this->issuesLogService;
        register_shutdown_function(static function () use ($issuesLogService, $section): void {
            $lastError = error_get_last();
            if ($lastError === null || !in_array($lastError['type'], self::FATAL_ERROR_TYPES, true)) {
                return;
            }
            // an out-of-memory fatal leaves no room to log it
            @ini_set('memory_limit', (string)(memory_get_usage(true) + 32 * 1024 * 1024));
            $issuesLogService->logThrowable(
                new ErrorException($lastError['message'], 0, $lastError['type'], $lastError['file'], $lastError['line']),
                LogLevel::CRITICAL,
                ['error.handled' => false],
                section: $section
            );
        });
    }
}
