<?php

declare(strict_types=1);

namespace DDD\Symfony\Messenger\Middleware;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Throwable;

/**
 * Every CONSUMED worker job starts on fresh process state, like a web request. A long-lived Messenger worker
 * otherwise serves a later job from what an earlier job left behind: a database session whose REPEATABLE READ
 * snapshot (or connection routing) predates the previous job's writes, the Doctrine unit of work, and the
 * process-static entity registries holding the earlier job's entities. Before a message carrying a
 * {@see ReceivedStamp} reaches its handler, this middleware runs {@see AppMessageHandler::resetWorkerStateForNewJob()}:
 * Doctrine unit-of-work caches cleared, every connection without an active transaction closed (lazy reconnect),
 * both static entity registries emptied.
 *
 * Registered FIRST on every bus by {@see \DDD\Symfony\CompilerPasses\FreshWorkerStateMiddlewarePass} — no app
 * configuration, no per-handler code. Two ways out:
 *  - per handler: an {@see AppMessageHandler} whose {@see AppMessageHandler::RESET_WORKER_STATE_BEFORE_JOB} is
 *    false keeps warm state; the middleware skips the reset when ANY handler of the message says so;
 *  - per app: container parameter `ddd.messenger.fresh_worker_state: false` removes the middleware entirely.
 *
 * Synchronous dispatches inside a request (no ReceivedStamp) are never touched: the request keeps its state.
 * Fail-soft: a reset problem never stops the job (a stale cache is the lesser evil next to a lost job).
 */
class FreshWorkerStateMiddleware implements MiddlewareInterface
{
    public function __construct(protected ?HandlersLocatorInterface $handlersLocator = null)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ReceivedStamp::class) !== null && $this->handlersWantFreshState($envelope)) {
            try {
                $this->resetWorkerState($this->firstAppMessageHandlerClass($envelope));
            } catch (Throwable) {
                // fail-soft, see the class docblock
            }
        }
        return $stack->next()->handle($envelope, $stack);
    }

    /**
     * False when any handler of the message is an {@see AppMessageHandler} that opted out through
     * {@see AppMessageHandler::RESET_WORKER_STATE_BEFORE_JOB}. Messages without a resolvable handler class (closures,
     * plain callables) and non-AppMessageHandler handlers get the default: fresh.
     */
    protected function handlersWantFreshState(Envelope $envelope): bool
    {
        if ($this->handlersLocator === null) {
            return true;
        }
        foreach ($this->handlersLocator->getHandlers($envelope) as $handlerDescriptor) {
            $handlerClass = $this->handlerClassFromDescriptorName($handlerDescriptor->getName());
            if ($handlerClass !== null && is_a($handlerClass, AppMessageHandler::class, true)
                && !$handlerClass::RESET_WORKER_STATE_BEFORE_JOB) {
                return false;
            }
        }
        return true;
    }

    /**
     * A descriptor name is `Class::method`, optionally suffixed with `@alias`; closures are named `Closure`.
     *
     * @return class-string|null
     */
    protected function handlerClassFromDescriptorName(string $descriptorName): ?string
    {
        $separatorPosition = strpos($descriptorName, '::');
        if ($separatorPosition === false) {
            return null;
        }
        $handlerClass = substr($descriptorName, 0, $separatorPosition);
        return class_exists($handlerClass) ? $handlerClass : null;
    }

    /**
     * The first handler of the message that is an {@see AppMessageHandler} — the class whose
     * {@see AppMessageHandler::resetWorkerStateForNewJob()} runs, so an application's own handler base can extend
     * the reset with the application's process statics. Null when no handler class is resolvable.
     *
     * @return class-string<AppMessageHandler>|null
     */
    protected function firstAppMessageHandlerClass(Envelope $envelope): ?string
    {
        if ($this->handlersLocator === null) {
            return null;
        }
        foreach ($this->handlersLocator->getHandlers($envelope) as $handlerDescriptor) {
            $handlerClass = $this->handlerClassFromDescriptorName($handlerDescriptor->getName());
            if ($handlerClass !== null && is_a($handlerClass, AppMessageHandler::class, true)) {
                return $handlerClass;
            }
        }
        return null;
    }

    /**
     * The reset itself — the message's handler class runs it (its application base may override the method with
     * the application's own statics on top of the framework's); the base handler when no class is resolvable. The
     * method a unit test overrides.
     *
     * @param class-string<AppMessageHandler>|null $handlerClass
     */
    protected function resetWorkerState(?string $handlerClass = null): void
    {
        $resetClass = $handlerClass ?? AppMessageHandler::class;
        $resetClass::resetWorkerStateForNewJob();
    }
}
