<?php

declare(strict_types=1);

namespace DDD\Symfony\Messenger\Middleware;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Domain\Common\Services\WorkerMonitoringService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Throwable;

/**
 * Measures every CONSUMED message for the worker monitoring: start, end and whether an exception left the handler
 * ({@see WorkerMonitoringService}). No handler is changed for it. While the handler runs, the message's dispatch origin
 * is the origin of every message the handler dispatches ({@see AppMessage::setDispatchOrigin()}).
 *
 * Registered on every bus by {@see \DDD\Symfony\CompilerPasses\FreshWorkerStateMiddlewarePass}, behind the fresh
 * state middleware and before the workspace origin guard — a message the guard refuses counts as thrown. Opt-out for
 * a whole app: parameter `ddd.messenger.worker_monitoring: false`; whether anything is written is decided by the
 * monitoring's own `enabled` setting.
 *
 * Synchronous dispatches are not measured (gate: {@see ConsumedByWorkerStamp}). Fail-soft: the monitoring never stops
 * a job and never replaces its exception.
 */
class WorkerMonitoringMiddleware implements MiddlewareInterface
{
    public function __construct(protected ?WorkerMonitoringService $workerMonitoringService = null)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ConsumedByWorkerStamp::class) === null) {
            return $stack->next()->handle($envelope, $stack);
        }
        $message = $envelope->getMessage();
        $outerDispatchOrigin = AppMessage::getDispatchOriginOfHandledMessage();
        AppMessage::setDispatchOriginOfHandledMessage($message instanceof AppMessage ? $message->dispatchOrigin : null);
        try {
            return $this->handleMeasured($envelope, $stack);
        } finally {
            AppMessage::setDispatchOriginOfHandledMessage($outerDispatchOrigin);
        }
    }

    protected function handleMeasured(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($this->workerMonitoringService === null) {
            return $stack->next()->handle($envelope, $stack);
        }
        try {
            $this->workerMonitoringService->startMessage($envelope);
        } catch (Throwable) {
            // fail-soft, see the class docblock
        }
        try {
            $handledEnvelope = $stack->next()->handle($envelope, $stack);
        } catch (Throwable $thrownThrowable) {
            $this->finishMessage($thrownThrowable);
            throw $thrownThrowable;
        }
        $this->finishMessage(null);
        return $handledEnvelope;
    }

    protected function finishMessage(?Throwable $thrownThrowable): void
    {
        try {
            $this->workerMonitoringService?->finishMessage($thrownThrowable);
        } catch (Throwable) {
            // fail-soft, see the class docblock
        }
    }
}
