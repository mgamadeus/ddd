<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageDispatchOrigin;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\IssuesLogEvent;
use DDD\Infrastructure\Logging\IssuesLogRedactor;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Logging\Sentry\SentryEventBuilder;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Symfony\EventListeners\IssuesCaptureSubscriber;
use Exception;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Routing\RouterInterface;
use Throwable;
use TypeError;

/** A message whose keys a failing job's event shows */
class OrderPaymentProbeMessage extends AppMessage
{
    public ?int $orderId = null;

    public ?string $paymentProvider = null;

    public ?string $apiToken = null;
}

/**
 * An error event a developer can act on: type and message without a prefix, the throw site as a relative path, the
 * structured frames with code context, the cause chain, the route parameters and the worker fields — all display-only,
 * the hashes stay those of {@see IssuesGoldenHashesTest}.
 */
class IssuesErrorEventQualityTest extends TestCase
{
    protected TestHandler $testHandler;

    protected function setUp(): void
    {
        $this->testHandler = new TestHandler();
        IssuesLogService::setSettings(new IssuesLogSettings());
    }

    protected function tearDown(): void
    {
        IssuesLogService::setSettings(null);
    }

    protected function createIssuesLogService(?Request $request = null): IssuesLogService
    {
        $requestStack = new RequestStack();
        if ($request !== null) {
            $requestStack->push($request);
        }
        return new IssuesLogService(new Logger('issues', [$this->testHandler]), $requestStack, $this->createMock(RouterInterface::class));
    }

    /** @return array<string, mixed> */
    protected function logAndGetContext(Throwable $throwable, ?Request $request = null): array
    {
        $this->createIssuesLogService($request)->logThrowable($throwable);
        return $this->testHandler->getRecords()[0]->context;
    }

    protected function throwWithoutMessage(): Exception
    {
        try {
            throw new Exception();
        } catch (Exception $exception) {
            return $exception;
        }
    }

    public function testAServiceExceptionCarriesTypeMessageThrowSiteFramesAndCodeContext(): void
    {
        $context = $this->logAndGetContext((new IssuesFixtureThrower())->runtimeError(1));
        $record = $this->testHandler->getRecords()[0];

        $this->assertSame('RuntimeException: failed for id 42', $record->message, 'no "Exception occurred:" prefix');
        $throwSite = $context['error.frames'][0];
        $this->assertSame('tests/Infrastructure/Logging/IssuesFixtureThrower.php', $throwSite['file'], 'relative to the project root');
        $this->assertSame(22, $throwSite['line']);
        $this->assertSame(IssuesFixtureThrower::class . '->runtimeError', $throwSite['function']);
        $this->assertTrue($throwSite['in_app']);
        $this->assertSame(19, $throwSite['code_context']['start_line']);
        $this->assertContains('                throw new RuntimeException($message);', $throwSite['code_context']['lines']);
        $this->assertSame('IssuesFixtureThrower.php', $context['log.origin.file.name'], 'the basename stays for the COARSE grouping');
        $this->assertStringStartsWith('#0 tests/Infrastructure/Logging/IssuesFixtureThrower.php(22): ', $context['error.stack_trace']);
        $this->assertStringContainsString('← thrown here', $context['error.stack_trace']);
        $frameworkFrames = array_filter($context['error.frames'], static fn(array $frame): bool => !$frame['in_app']);
        $this->assertNotEmpty($frameworkFrames, 'PHPUnit frames are framework frames');
        $this->assertStringContainsString('framework frames', $context['error.stack_trace'], 'collapsed after the first three');
        $this->assertCount(2, array_filter($context['error.frames'], static fn(array $frame): bool => isset($frame['code_context'])));
    }

    public function testATypeErrorAndAWrappedExceptionCarryTheirCauses(): void
    {
        try {
            (static fn(int $orderId): int => $orderId)('not a number');
        } catch (TypeError $typeError) {
        }
        $wrapped = new LogicException('checkout failed', 0, new RuntimeException('payment of anna@example.com declined', 0, $typeError));

        $context = $this->logAndGetContext($wrapped);

        $this->assertSame('LogicException: checkout failed', $this->testHandler->getRecords()[0]->message);
        $this->assertCount(2, $context['error.causes']);
        $this->assertSame(RuntimeException::class, $context['error.causes'][0]['type']);
        $this->assertSame('payment of [EMAIL] declined', $context['error.causes'][0]['message'], 'sanitised like the message');
        $this->assertSame(TypeError::class, $context['error.causes'][1]['type']);
        $this->assertStringStartsWith('tests/Infrastructure/Logging/IssuesErrorEventQualityTest.php:', $context['error.causes'][1]['file']);
        $this->assertLessThanOrEqual(IssuesLogService::CAUSE_FRAMES, count($context['error.causes'][1]['frames']));
    }

    public function testAnExceptionWithoutMessageSaysWhereItWasThrown(): void
    {
        $this->logAndGetContext($this->throwWithoutMessage());

        $this->assertMatchesRegularExpression(
            '/^Exception \(no message\) at tests\/Infrastructure\/Logging\/IssuesErrorEventQualityTest\.php:\d+$/',
            $this->testHandler->getRecords()[0]->message
        );
    }

    public function testAnHttpEventCarriesTheRouteParameters(): void
    {
        $request = Request::create('/api/admin/locations/12/orders/881', 'PATCH');
        $request->attributes->set('_route', 'admin_location_order_update');
        $request->attributes->set('_route_params', ['locationId' => '12', 'orderId' => '881', 'resetToken' => 's3cr3t', '_locale' => 'de']);

        $context = $this->logAndGetContext(new RuntimeException('failed'), $request);

        $this->assertSame(['locationId' => '12', 'orderId' => '881', 'resetToken' => IssuesLogRedactor::REDACTED], $context['route_parameters']);
    }

    public function testAFailingWorkerMessageCarriesHandlerKeysAttemptOriginAndAge(): void
    {
        $orderPaymentProbeMessage = new OrderPaymentProbeMessage();
        $orderPaymentProbeMessage->orderId = 881;
        $orderPaymentProbeMessage->paymentProvider = 'stripe';
        $orderPaymentProbeMessage->apiToken = 'tok_secret';
        $orderPaymentProbeMessage->accountId = 7;
        $appMessageDispatchOrigin = new AppMessageDispatchOrigin();
        $appMessageDispatchOrigin->route = 'public_checkout_pay';
        $appMessageDispatchOrigin->dispatchedAt = DateTime::fromTimestamp(time() - 90);
        $orderPaymentProbeMessage->dispatchOrigin = $appMessageDispatchOrigin;
        $envelope = new Envelope($orderPaymentProbeMessage, [new RedeliveryStamp(2)]);
        $handlerFailure = new RuntimeException('provider timeout');
        $captureSubscriber = new IssuesCaptureSubscriber($this->createIssuesLogService());

        $captureSubscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            $envelope,
            'process_payment',
            new HandlerFailedException($envelope, ['App\\Domain\\Orders\\MessageHandlers\\OrderPaymentHandler::__invoke' => $handlerFailure])
        ));

        $context = $this->testHandler->getRecords()[0]->context;
        $this->assertSame('App\\Domain\\Orders\\MessageHandlers\\OrderPaymentHandler::__invoke', $context['messenger.handler']);
        $this->assertSame(['orderId' => 881, 'paymentProvider' => 'stripe', 'apiToken' => IssuesLogRedactor::REDACTED], $context['messenger.message_keys']);
        $this->assertSame(3, $context['messenger.attempt']);
        $this->assertTrue($context['messenger.final'], 'no retry follows: the message goes to the failure transport');
        $this->assertSame('public_checkout_pay', $context['messenger.origin.route']);
        $this->assertSame(7, $context['messenger.origin.account_id']);
        $this->assertGreaterThanOrEqual(90, $context['messenger.age_seconds']);
        $this->assertSame('RuntimeException: provider timeout', $this->testHandler->getRecords()[0]->message);
    }

    public function testATooLargeEventLosesTheDisplayDetailBeforeTheTrace(): void
    {
        $context = $this->logAndGetContext((new IssuesFixtureThrower())->runtimeError(1));
        $context['error.causes'] = [['type' => 'A', 'message' => str_repeat('a', 3000)], ['type' => 'B', 'message' => str_repeat('b', 3000)]];
        $issuesLogEvent = new IssuesLogEvent('RuntimeException: failed', $context);

        (new IssuesLogRedactor())->capEvent($issuesLogEvent, 6000);

        $this->assertArrayNotHasKey('error.fingerprint', $issuesLogEvent->context, 'dropped first');
        $this->assertCount(1, $issuesLogEvent->context['error.causes'], 'causes beyond the first go');
        $this->assertTrue($issuesLogEvent->context['error.frames'][0]['in_app'] ?? false, 'the throw site stays');
        foreach ($issuesLogEvent->context['error.frames'] as $frameIndex => $frame) {
            $this->assertTrue($frameIndex === 0 || $frame['in_app'], 'framework frames go');
        }
    }

    public function testSentryFramesComeFromErrorFrames(): void
    {
        $context = $this->logAndGetContext((new IssuesFixtureThrower())->runtimeError(1));
        $jsonLine = (string)json_encode(['@timestamp' => '2026-10-30T10:00:00Z', 'log.level' => 'ERROR', 'message' => 'RuntimeException: failed'] + $context);

        $sentryEvent = json_decode((string)(new SentryEventBuilder())->buildEventJson($jsonLine), true);

        $sentryFrames = $sentryEvent['exception']['values'][0]['stacktrace']['frames'];
        $throwSite = $sentryFrames[array_key_last($sentryFrames)];
        $this->assertSame('tests/Infrastructure/Logging/IssuesFixtureThrower.php', $throwSite['filename']);
        $this->assertSame(22, $throwSite['lineno']);
        $this->assertSame('                throw new RuntimeException($message);', $throwSite['context_line']);
    }

    public function testTheDispatchOriginSurvivesTheMessageSerializer(): void
    {
        $orderPaymentProbeMessage = new OrderPaymentProbeMessage();
        $orderPaymentProbeMessage->orderId = 881;
        $appMessageDispatchOrigin = new AppMessageDispatchOrigin();
        $appMessageDispatchOrigin->route = 'public_checkout_pay';
        $appMessageDispatchOrigin->dispatchedAt = DateTime::fromTimestamp(1730282400);
        $orderPaymentProbeMessage->dispatchOrigin = $appMessageDispatchOrigin;

        $decodedMessage = $orderPaymentProbeMessage->decode($orderPaymentProbeMessage->encode(new Envelope($orderPaymentProbeMessage)))->getMessage();

        $this->assertInstanceOf(AppMessageDispatchOrigin::class, $decodedMessage->dispatchOrigin);
        $this->assertSame('public_checkout_pay', $decodedMessage->dispatchOrigin->route);
        $this->assertSame(1730282400, $decodedMessage->dispatchOrigin->dispatchedAt->getTimestamp());
    }
}
