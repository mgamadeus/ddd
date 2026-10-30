<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Commands\Base\Messages;

use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Symfony\Commands\Base\Messages\ProcessCLIMessage;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\RouterInterface;
use TestContainer;

/**
 * A handler run in its workspace's CLI process fails: its own error, unwrapped from HandlerFailedException, reaches
 * the issues log as uncaught (the parent only sees the exit code).
 */
class ProcessCLIMessageIssuesLogTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(TestContainer::$overrides[IssuesLogService::class]);
        IssuesLogService::setSettings(null);
    }

    public function testTheHandlersOwnErrorIsLogged(): void
    {
        IssuesLogService::setSettings(new IssuesLogSettings());
        $testHandler = new TestHandler();
        TestContainer::$overrides[IssuesLogService::class] = new IssuesLogService(
            new Logger('issues', [$testHandler]),
            new RequestStack(),
            $this->createMock(RouterInterface::class)
        );
        $handlerError = new RuntimeException('translation provider down');
        $processCLIMessage = new class extends ProcessCLIMessage {
            public function failWith(\Throwable $throwable): void
            {
                $this->logHandlerFailureToIssues($throwable, 'App\\MessageHandlers\\TranslateHandler');
            }
        };

        $processCLIMessage->failWith(new HandlerFailedException(new Envelope(new \stdClass()), [$handlerError]));

        self::assertCount(1, $testHandler->getRecords());
        $record = $testHandler->getRecords()[0];
        self::assertSame(RuntimeException::class, $record->context['error.type']);
        self::assertFalse($record->context['error.handled']);
        self::assertSame(IssuesLogService::LOG_MESSAGE_SECTION_MESSENGER, $record->context['section']);
        self::assertStringStartsWith('App\\MessageHandlers\\TranslateHandler failed', $record->context['messenger.custom_message']);
    }
}
