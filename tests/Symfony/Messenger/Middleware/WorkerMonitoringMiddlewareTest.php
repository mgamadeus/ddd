<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Messenger\Middleware;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageDispatchOrigin;
use DDD\Symfony\Messenger\Middleware\WorkerMonitoringMiddleware;
use DDD\Tests\Symfony\Messenger\Middleware\Fixtures\DispatchOriginProbeMessage;
use DDD\Tests\Symfony\Messenger\Middleware\Fixtures\RecordingWorkerMonitoringService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;

class WorkerMonitoringMiddlewareTest extends TestCase
{
    protected function nextThatThrows(string $message): MiddlewareInterface
    {
        $next = $this->createMock(MiddlewareInterface::class);
        $next->method('handle')->willThrowException(new RuntimeException($message));
        return $next;
    }

    public function testAConsumedMessageIsMeasured(): void
    {
        $recordingWorkerMonitoringService = new RecordingWorkerMonitoringService();
        $envelope = new Envelope(new stdClass(), [new ConsumedByWorkerStamp()]);

        (new WorkerMonitoringMiddleware($recordingWorkerMonitoringService))->handle($envelope, new StackMiddleware());

        self::assertSame(['start', 'finish'], $recordingWorkerMonitoringService->calls);
    }

    public function testASynchronousDispatchIsNotMeasured(): void
    {
        $recordingWorkerMonitoringService = new RecordingWorkerMonitoringService();

        (new WorkerMonitoringMiddleware($recordingWorkerMonitoringService))->handle(new Envelope(new stdClass()), new StackMiddleware());

        self::assertSame([], $recordingWorkerMonitoringService->calls);
    }

    public function testAThrownExceptionIsReportedAndPassedOn(): void
    {
        $recordingWorkerMonitoringService = new RecordingWorkerMonitoringService();
        $envelope = new Envelope(new stdClass(), [new ConsumedByWorkerStamp()]);

        try {
            $workerMonitoringMiddleware = new WorkerMonitoringMiddleware($recordingWorkerMonitoringService);
            // the stack's iterator starts at the running middleware
            $workerMonitoringMiddleware->handle($envelope, new StackMiddleware([$workerMonitoringMiddleware, $this->nextThatThrows('handler failed')]));
            self::fail('the handler exception must pass');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('handler failed', $runtimeException->getMessage());
        }
        self::assertSame(['start', 'finish:handler failed'], $recordingWorkerMonitoringService->calls);
    }

    public function testABrokenMonitoringNeverStopsTheJob(): void
    {
        $recordingWorkerMonitoringService = new RecordingWorkerMonitoringService();
        $recordingWorkerMonitoringService->startThrows = true;
        $envelope = new Envelope(new stdClass(), [new ConsumedByWorkerStamp()]);

        $handledEnvelope = (new WorkerMonitoringMiddleware($recordingWorkerMonitoringService))->handle($envelope, new StackMiddleware());

        self::assertSame($envelope->getMessage(), $handledEnvelope->getMessage());
        self::assertSame(['start', 'finish'], $recordingWorkerMonitoringService->calls);
    }

    public function testAMessageDispatchedByAHandlerCarriesTheOriginOfTheHandledMessage(): void
    {
        $handledMessage = new DispatchOriginProbeMessage();
        $handledMessage->dispatchOrigin = new AppMessageDispatchOrigin();
        $handledMessage->dispatchOrigin->route = 'orders_create';
        $handledMessage->dispatchOrigin->command = null;
        $dispatchedByHandler = new DispatchOriginProbeMessage();
        $handler = $this->createMock(MiddlewareInterface::class);
        $handler->method('handle')->willReturnCallback(static function (Envelope $envelope) use ($dispatchedByHandler): Envelope {
            $dispatchedByHandler->setDispatchOrigin();
            return $envelope;
        });
        $workerMonitoringMiddleware = new WorkerMonitoringMiddleware(null);

        $workerMonitoringMiddleware->handle(
            new Envelope($handledMessage, [new ConsumedByWorkerStamp()]),
            new StackMiddleware([$workerMonitoringMiddleware, $handler])
        );
        $dispatchedOutsideAHandler = new DispatchOriginProbeMessage();
        $dispatchedOutsideAHandler->setDispatchOrigin();

        self::assertSame('orders_create', $dispatchedByHandler->dispatchOrigin->route);
        self::assertNull($dispatchedByHandler->dispatchOrigin->command, 'not the consume command of the worker');
        self::assertNotNull($dispatchedByHandler->dispatchOrigin->dispatchedAt);
        self::assertNull(AppMessage::getDispatchOriginOfHandledMessage(), 'put back after the handler');
        self::assertNull($dispatchedOutsideAHandler->dispatchOrigin->route);
        self::assertSame(($_SERVER['argv'] ?? [])[1] ?? null, $dispatchedOutsideAHandler->dispatchOrigin->command, 'outside a handler: the console run itself');
    }

    public function testTheHandledOriginIsPutBackWhenTheHandlerThrows(): void
    {
        $handledMessage = new DispatchOriginProbeMessage();
        $handledMessage->dispatchOrigin = new AppMessageDispatchOrigin();
        $workerMonitoringMiddleware = new WorkerMonitoringMiddleware(new RecordingWorkerMonitoringService());

        try {
            $workerMonitoringMiddleware->handle(
                new Envelope($handledMessage, [new ConsumedByWorkerStamp()]),
                new StackMiddleware([$workerMonitoringMiddleware, $this->nextThatThrows('handler failed')])
            );
            self::fail('the handler exception must pass');
        } catch (RuntimeException) {
        }

        self::assertNull(AppMessage::getDispatchOriginOfHandledMessage());
    }
}
