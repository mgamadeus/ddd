<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Messenger\Middleware;

use DDD\Symfony\Messenger\Middleware\WorkerMonitoringMiddleware;
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
}
