<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DateTimeImmutable;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Logging\IssuesDuplicateRequestRecordFilterHandler;
use DDD\Infrastructure\Logging\IssuesLogRedactor;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Logging\IssuesLogSinkHandler;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Symfony\EventListeners\IssuesCaptureSubscriber;
use Elastic\Monolog\Formatter\ElasticCommonSchemaFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

/**
 * The issues write side: the event fields, log-once, the sink targets, the RC compatibility (the same JSON line as RC's
 * stream handler with the ECS formatter; RC's subclass pattern still runs) and the capture subscriber.
 */
class IssuesLogServiceTest extends TestCase
{
    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/ddd-issues-' . bin2hex(random_bytes(4));
        mkdir($this->temporaryDirectory);
        IssuesLogService::setSettings(new IssuesLogSettings());
    }

    protected function tearDown(): void
    {
        IssuesLogService::setSettings(null);
        foreach (glob($this->temporaryDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporaryDirectory);
    }

    protected function serviceWith(LoggerInterface $logger, ?Request $request = null): IssuesLogService
    {
        $requestStack = new RequestStack();
        if ($request !== null) {
            $requestStack->push($request);
        }
        return new IssuesLogService($logger, $requestStack, $this->createMock(RouterInterface::class));
    }

    protected function loggerWith(TestHandler $testHandler): Logger
    {
        return new Logger('issues', [$testHandler]);
    }

    public function testAnEventCarriesTheStableHashesTheEventIdAndTheUnchangedFields(): void
    {
        $testHandler = new TestHandler();
        $this->serviceWith($this->loggerWith($testHandler))->logThrowable(new RuntimeException('failed'));

        $context = $testHandler->getRecords()[0]->context;
        foreach (['error.group_hash', 'error.issue_hash', 'event.id', 'error.type', 'error.fingerprint', 'error.fingerprint_hash',
                     'error.fingerprint_short', 'log.origin.file.name', 'service.name', 'service.environment', 'section'] as $field) {
            $this->assertArrayHasKey($field, $context, $field);
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $context['event.id']);
        $this->assertSame(RuntimeException::class, $context['error.type']);
    }

    public function testAThrowableIsLoggedOnce(): void
    {
        $testHandler = new TestHandler();
        $issuesLogService = $this->serviceWith($this->loggerWith($testHandler));
        $exception = new RuntimeException('failed');

        $issuesLogService->logThrowable($exception);
        $issuesLogService->logThrowable($exception, LogLevel::CRITICAL);

        $this->assertCount(1, $testHandler->getRecords());
        $this->assertTrue(IssuesLogService::wasLogged($exception));
    }

    public function testTheMessageIsSanitisedAndTheContextRedacted(): void
    {
        $testHandler = new TestHandler();
        $this->serviceWith($this->loggerWith($testHandler))
            ->logThrowable(new RuntimeException('no account for anna@example.com'), additionalContext: ['token' => 'abc']);

        $record = $testHandler->getRecords()[0];
        $this->assertSame('RuntimeException: no account for [EMAIL]', $record->message);
        $this->assertSame(IssuesLogRedactor::REDACTED, $record->context['token']);
    }

    public function testConfiguredHeaderPrefixesAreCopiedAndOthersNot(): void
    {
        IssuesLogService::setSettings(new IssuesLogSettings(headerPrefixes: ['x-']));
        $request = Request::create('/orders', 'POST', server: ['HTTP_X_CLIENT_VERSION' => '4.2', 'HTTP_ACCEPT_LANGUAGE' => 'de']);
        $request->attributes->set('_route', 'api_orders_create');
        $testHandler = new TestHandler();

        $this->serviceWith($this->loggerWith($testHandler), $request)->logThrowable(new RuntimeException('failed'));

        $context = $testHandler->getRecords()[0]->context;
        $this->assertSame(['4.2'], $context['request_headers']['x-client-version']);
        $this->assertArrayNotHasKey('accept-language', $context['request_headers']);
        $this->assertSame('api_orders_create', $context['route_name']);
        $this->assertSame('POST', $context['http.request.method']);
    }

    /** RC's handler: stream, warning, bubble false, use_locking, the ECS formatter — the Core sink writes the same line */
    public function testTheFileSinkWritesTheSameLineAsRcsStreamHandler(): void
    {
        $record = new LogRecord(
            new DateTimeImmutable('2026-10-28 12:00:00'),
            'issues',
            Level::Critical,
            'Exception occurred: failed',
            ['error.type' => RuntimeException::class, 'error.fingerprint_short' => 'route|RuntimeException~abc', 'route_name' => 'api_x'],
            ['uid' => 'abc123', 'server' => 'host-1']
        );
        $rcHandler = new StreamHandler($this->temporaryDirectory . '/issues_rc.log', Level::Warning, false, null, true);
        $rcHandler->setFormatter(new ElasticCommonSchemaFormatter());
        $rcHandler->handle($record);
        $sinkHandler = (new IssuesLogSinkHandler('prod', ''))->setSettings(new IssuesLogSettings(
            target: IssuesLogSettings::TARGET_FILE,
            path: $this->temporaryDirectory,
            fileName: 'issues_{environment}.log',
        ));

        $this->assertTrue($sinkHandler->handle($record), 'the record stops at the sink (bubble false)');
        $sinkHandler->close();

        $this->assertSame(
            file_get_contents($this->temporaryDirectory . '/issues_rc.log'),
            file_get_contents($this->temporaryDirectory . '/issues_prod.log')
        );
    }

    public function testWithoutATargetTheSinkLeavesTheRecordsToTheApplicationsHandlers(): void
    {
        $sinkHandler = (new IssuesLogSinkHandler('prod', $this->temporaryDirectory))->setSettings(new IssuesLogSettings());
        $record = new LogRecord(new DateTimeImmutable(), 'issues', Level::Error, 'x');

        $this->assertFalse($sinkHandler->isHandling($record));
        $this->assertFalse($sinkHandler->handle($record));
    }

    public function testNoneDropsAndTheLevelFilters(): void
    {
        $noneHandler = (new IssuesLogSinkHandler('prod', ''))->setSettings(new IssuesLogSettings(target: IssuesLogSettings::TARGET_NONE));
        $this->assertTrue($noneHandler->handle(new LogRecord(new DateTimeImmutable(), 'issues', Level::Error, 'x')));

        $fileHandler = (new IssuesLogSinkHandler('prod', ''))->setSettings(new IssuesLogSettings(
            target: IssuesLogSettings::TARGET_FILE,
            path: $this->temporaryDirectory,
        ));
        $this->assertFalse($fileHandler->isHandling(new LogRecord(new DateTimeImmutable(), 'issues', Level::Info, 'x')));
    }

    public function testADateInTheFileNameStartsAFilePerDay(): void
    {
        $settings = new IssuesLogSettings(path: '/var/log/issues', fileName: 'issues_{environment}_{date}.log');

        $this->assertSame('/var/log/issues/issues_prod_2026-10-28.log', $settings->getFilePath('prod', '2026-10-28'));
        $this->assertTrue($settings->isDateRotated());
    }

    /** RC's subclass bypasses the parent constructor and overrides logThrowable — it keeps running against the new base */
    public function testRcsSubclassPatternStillRuns(): void
    {
        $testHandler = new TestHandler();
        $rcStyleService = new class ($this->loggerWith($testHandler), new RequestStack(), $this->createMock(RouterInterface::class)) extends IssuesLogService {
            public function __construct(LoggerInterface $issuesLogger, RequestStack $requestStack, RouterInterface $router)
            {
                $this->issuesLogger = $issuesLogger;
                $this->requestStack = $requestStack;
                $this->router = $router;
            }

            public function logThrowable(
                Throwable $throwable,
                string $level = LogLevel::ERROR,
                array $additionalContext = [],
                bool $includeFullStackTrace = false,
                string $section = self::LOG_MESSAGE_SECTION_HTTP,
            ): void {
                $additionalContext['section'] = $section;
                $context = $this->compileExceptionContext($throwable, $additionalContext, $includeFullStackTrace);
                $this->safeLog($level, 'Exception occurred: ' . $throwable->getMessage(), $context);
            }
        };

        $rcStyleService->logThrowable(new RuntimeException('failed'), LogLevel::CRITICAL);

        $this->assertCount(1, $testHandler->getRecords());
        $this->assertArrayHasKey('error.fingerprint_short', $testHandler->getRecords()[0]->context);
    }

    protected function exceptionEvent(Throwable $throwable): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/x'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable
        );
    }

    public function testTheCaptureLogsA500OnceAndA404NotAtAll(): void
    {
        $testHandler = new TestHandler();
        $captureSubscriber = new IssuesCaptureSubscriber($this->serviceWith($this->loggerWith($testHandler)));
        $serverError = new RuntimeException('boom');

        $captureSubscriber->onKernelException($this->exceptionEvent($serverError));
        $captureSubscriber->onKernelException($this->exceptionEvent($serverError));
        $captureSubscriber->onKernelException($this->exceptionEvent(new NotFoundException('missing')));

        $this->assertCount(1, $testHandler->getRecords());
        $record = $testHandler->getRecords()[0];
        $this->assertSame(Level::Critical, $record->level);
        $this->assertFalse($record->context['error.handled']);
        $this->assertSame(500, $record->context['http_status']);
    }

    public function testA4xxIsLoggedWhenItsLevelIsConfigured(): void
    {
        IssuesLogService::setSettings(new IssuesLogSettings(clientErrorLevel: LogLevel::INFO));
        $testHandler = new TestHandler();

        (new IssuesCaptureSubscriber($this->serviceWith($this->loggerWith($testHandler))))
            ->onKernelException($this->exceptionEvent(new NotFoundException('missing')));

        $this->assertSame(Level::Info, $testHandler->getRecords()[0]->level);
        $this->assertSame(404, $testHandler->getRecords()[0]->context['http_status']);
    }

    public function testTheCaptureCanBeSwitchedOff(): void
    {
        IssuesLogService::setSettings(new IssuesLogSettings(captureEnabled: false));
        $testHandler = new TestHandler();

        (new IssuesCaptureSubscriber($this->serviceWith($this->loggerWith($testHandler))))
            ->onKernelException($this->exceptionEvent(new RuntimeException('boom')));

        $this->assertCount(0, $testHandler->getRecords());
    }

    /** Symfony's own "Uncaught PHP Exception" record of an exception the issues log has stops at the filter */
    public function testSymfonysDuplicateRequestRecordIsDropped(): void
    {
        $loggedException = new RuntimeException('boom');
        IssuesLogService::markLogged($loggedException);
        $requestHandler = new TestHandler();
        $requestLogger = new Logger('request', [new IssuesDuplicateRequestRecordFilterHandler(), $requestHandler]);

        $requestLogger->critical('Uncaught PHP Exception', ['exception' => $loggedException]);
        $requestLogger->critical('Uncaught PHP Exception', ['exception' => new RuntimeException('not logged')]);

        $this->assertCount(1, $requestHandler->getRecords());
        $this->assertSame('not logged', $requestHandler->getRecords()[0]->context['exception']->getMessage());
    }
    /** A container sets ISSUES_* as process environment variables, which do not always reach $_ENV */
    public function testTheSettingsReadTheProcessEnvironment(): void
    {
        putenv('ISSUES_LOG_TARGET=file');
        putenv('ISSUES_CAPTURE_ENABLED=0');
        try {
            $settings = IssuesLogSettings::fromEnvironment('/var/log/app/issues');
        } finally {
            putenv('ISSUES_LOG_TARGET');
            putenv('ISSUES_CAPTURE_ENABLED');
        }

        $this->assertSame(IssuesLogSettings::TARGET_FILE, $settings->target);
        $this->assertFalse($settings->captureEnabled);
        $this->assertSame('/var/log/app/issues', $settings->path);
    }

    public function testAFailedMessageIsLoggedAsTheHandlersOwnErrorAndOnlyOnce(): void
    {
        $testHandler = new TestHandler();
        $issuesLogService = $this->serviceWith($this->loggerWith($testHandler));
        $captureSubscriber = new IssuesCaptureSubscriber($issuesLogService);
        $envelope = new \Symfony\Component\Messenger\Envelope(new \stdClass());
        $uncaughtError = new RuntimeException('qr scan failed');
        $handledError = new RuntimeException('translation failed');
        // a handler that logged its error, then gave up on the message
        $issuesLogService->logThrowable($handledError, section: IssuesLogService::LOG_MESSAGE_SECTION_MESSENGER);
        $unrecoverable = new \Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException('give up', 0, $handledError);

        $captureSubscriber->onWorkerMessageFailed(new \Symfony\Component\Messenger\Event\WorkerMessageFailedEvent(
            $envelope,
            'async',
            new \Symfony\Component\Messenger\Exception\HandlerFailedException($envelope, [$uncaughtError])
        ));
        $captureSubscriber->onWorkerMessageFailed(new \Symfony\Component\Messenger\Event\WorkerMessageFailedEvent(
            $envelope,
            'async',
            new \Symfony\Component\Messenger\Exception\HandlerFailedException($envelope, [$unrecoverable])
        ));

        $records = $testHandler->getRecords();
        // the handled error once (by the handler), the uncaught one as itself — not as Messenger's wrapper
        $this->assertCount(2, $records);
        $this->assertSame(RuntimeException::class, $records[1]->context['error.type']);
        $this->assertSame(__FILE__, $uncaughtError->getFile());
        $this->assertSame(basename(__FILE__), $records[1]->context['log.origin.file.name']);
        $this->assertFalse($records[1]->context['error.handled']);
    }

    public function testAFailedMessageCarriesItsRetryCountButNoStampList(): void
    {
        $testHandler = new TestHandler();
        $captureSubscriber = new IssuesCaptureSubscriber($this->serviceWith($this->loggerWith($testHandler)));
        $envelope = new \Symfony\Component\Messenger\Envelope(new \stdClass(), [new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(2)]);

        $captureSubscriber->onWorkerMessageFailed(new \Symfony\Component\Messenger\Event\WorkerMessageFailedEvent(
            $envelope,
            'async',
            new \Symfony\Component\Messenger\Exception\HandlerFailedException($envelope, [new RuntimeException('third try failed')])
        ));

        $context = $testHandler->getRecords()[0]->context;
        $this->assertSame(2, $context['messenger.retry_count']);
        $this->assertSame(3, $context['messenger.attempt']);
        $this->assertArrayNotHasKey('messenger.stamps', $context, 'the stamp class list was noise on every worker event');
    }
}
