<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Services\IssuesLogService;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

/**
 * Caught job errors reach the issues log: logIssue() with the established context keys and the message's scalar
 * properties, logShortException() next to its Monolog line, once per throwable, and a failing issues log never leaves
 * the handler's catch block.
 */
class AppMessageHandlerIssuesLogTest extends TestCase
{
    protected TestHandler $issuesTestHandler;

    protected TestHandler $messengerTestHandler;

    protected function setUp(): void
    {
        IssuesLogService::setSettings(new IssuesLogSettings());
        $this->issuesTestHandler = new TestHandler();
        $this->messengerTestHandler = new TestHandler();
    }

    protected function tearDown(): void
    {
        IssuesLogService::setSettings(null);
    }

    protected function createHandler(?IssuesLogService $issuesLogService = null): AppMessageHandler
    {
        $issuesLogService ??= new IssuesLogService(
            new Logger('issues', [$this->issuesTestHandler]),
            new RequestStack(),
            $this->createMock(RouterInterface::class)
        );
        return new class ($issuesLogService, new Logger('messenger', [$this->messengerTestHandler])) extends AppMessageHandler {
            public function failWithLogIssue(Throwable $throwable, AppMessage $appMessage, ?string $customMessage = null): void
            {
                $this->logIssue($throwable, $appMessage, $customMessage);
            }

            public function failWithShortException(LoggerInterface $logger, string $context, Throwable $throwable): void
            {
                $this->logShortException($logger, $context, $throwable);
            }
        };
    }

    protected function createMessage(): AppMessage
    {
        $appMessage = new class extends AppMessage {
            public ?int $qrScanId = 42;
            public string $transportName = 'process_qr_scan';
            public array $ids = [1, 2];
        };
        $appMessage->accountId = 7;
        return $appMessage;
    }

    public function testLogIssueWritesTheEstablishedContextKeys(): void
    {
        $this->createHandler()->failWithLogIssue(new RuntimeException('qr scan failed'), $this->createMessage(), 'QR scan 42');

        self::assertCount(1, $this->issuesTestHandler->getRecords());
        $record = $this->issuesTestHandler->getRecords()[0];
        self::assertSame('CRITICAL', $record->level->getName());
        self::assertSame('QR scan 42', $record->context['messenger.custom_message']);
        $payload = json_decode($record->context['messenger.message_payload'], true);
        self::assertSame(42, $payload['qrScanId']);
        self::assertSame('process_qr_scan', $payload['transportName']);
        self::assertSame(7, $payload['accountId']);
        self::assertArrayNotHasKey('ids', $payload);
        self::assertSame(IssuesLogService::LOG_MESSAGE_SECTION_MESSENGER, $record->context['section']);
    }

    public function testLogShortExceptionAlsoReachesTheIssuesLogOnce(): void
    {
        $appMessageHandler = $this->createHandler();
        $throwable = new RuntimeException('mail failed');
        $messengerLogger = new Logger('messenger', [$this->messengerTestHandler]);

        $appMessageHandler->failWithShortException($messengerLogger, 'FAIL: sending mail', $throwable);
        // a handler that also calls logIssue() for the same throwable: still one issues event
        $appMessageHandler->failWithLogIssue($throwable, $this->createMessage());

        self::assertCount(1, $this->messengerTestHandler->getRecords());
        self::assertStringContainsString('FAIL: sending mail error [RuntimeException #0] mail failed', $this->messengerTestHandler->getRecords()[0]->message);
        self::assertCount(1, $this->issuesTestHandler->getRecords());
        $record = $this->issuesTestHandler->getRecords()[0];
        self::assertSame('FAIL: sending mail', $record->context['messenger.custom_message']);
        self::assertSame(IssuesLogService::LOG_MESSAGE_SECTION_MESSENGER, $record->context['section']);
        self::assertArrayHasKey('error.group_hash', $record->context);
    }

    public function testAFailingIssuesLogNeverLeavesTheCatchBlock(): void
    {
        $failingIssuesLogService = $this->createMock(IssuesLogService::class);
        $failingIssuesLogService->method('logThrowable')->willThrowException(new RuntimeException('issues sink down'));
        $appMessageHandler = $this->createHandler($failingIssuesLogService);
        $messengerLogger = new Logger('messenger', [$this->messengerTestHandler]);

        $appMessageHandler->failWithShortException($messengerLogger, 'FAIL: job', new RuntimeException('job failed'));
        $appMessageHandler->failWithLogIssue(new RuntimeException('job failed'), $this->createMessage());

        self::assertCount(1, $this->messengerTestHandler->getRecords());
    }
}
