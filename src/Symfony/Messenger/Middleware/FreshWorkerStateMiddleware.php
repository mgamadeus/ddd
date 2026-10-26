<?php

declare(strict_types=1);

namespace DDD\Symfony\Messenger\Middleware;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Throwable;

/**
 * Every CONSUMED worker job starts on fresh process state, like a web request. A long-lived Messenger worker
 * otherwise serves a later job from what an earlier job left behind: a database session whose REPEATABLE READ
 * snapshot (or connection routing) predates the previous job's writes, the Doctrine unit of work, and the
 * process-static entity registries holding the earlier job's entities. Before a message the Worker consumed
 * ({@see ConsumedByWorkerStamp}) reaches its handler, this middleware runs
 * {@see AppMessageHandler::resetWorkerStateForNewJob()}: Doctrine unit-of-work caches cleared, every connection without
 * an active transaction closed (lazy reconnect), both static entity registries emptied.
 *
 * After the job — whether it returned or threw, and independent of the reset opt-out — a transaction the HANDLER left
 * open is rolled back on every connection this process has (all scopes), so it cannot swallow the next message's
 * writes on the same worker. Only a transaction that was not open before the handler is touched.
 *
 * Registered FIRST on every bus by {@see \DDD\Symfony\CompilerPasses\FreshWorkerStateMiddlewarePass} — no app
 * configuration, no per-handler code. Two ways out:
 *  - per handler: an {@see AppMessageHandler} whose {@see AppMessageHandler::RESET_WORKER_STATE_BEFORE_JOB} is
 *    false keeps warm state; the middleware skips the reset when ANY handler of the message says so;
 *  - per app: container parameter `ddd.messenger.fresh_worker_state: false` removes the middleware entirely.
 *
 * Synchronous dispatches are never touched — a request keeps its state and its transaction. The gate is
 * ConsumedByWorkerStamp, which only the Worker adds: a `sync://` transport adds a ReceivedStamp as well.
 * Fail-soft: a reset or cleanup problem never stops the job or replaces its exception.
 */
class FreshWorkerStateMiddleware implements MiddlewareInterface
{
    public function __construct(protected ?HandlersLocatorInterface $handlersLocator = null)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ConsumedByWorkerStamp::class) === null) {
            return $stack->next()->handle($envelope, $stack);
        }
        if ($this->handlersWantFreshState($envelope)) {
            try {
                $this->resetWorkerState($this->firstAppMessageHandlerClass($envelope));
            } catch (Throwable) {
                // fail-soft, see the class docblock
            }
        }
        $transactionNestingLevelsBeforeJob = $this->getTransactionNestingLevelsByScope();
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->rollBackTransactionsLeftOpenByJob($envelope, $transactionNestingLevelsBeforeJob);
        }
    }

    /**
     * The transaction nesting level of every connection this process has, by scope — read through
     * {@see EntityManagerFactory::getExistingInstance()}, which never creates a connection for a scope the app does not
     * use.
     *
     * @return int[] Nesting level by scope
     */
    protected function getTransactionNestingLevelsByScope(): array
    {
        $transactionNestingLevelsByScope = [];
        foreach (EntityManagerFactory::getInstantiatedScopes() as $scope) {
            try {
                $transactionNestingLevelsByScope[$scope] = EntityManagerFactory::getExistingInstance($scope)
                    ?->getConnection()->getTransactionNestingLevel() ?? 0;
            } catch (Throwable) {
                $transactionNestingLevelsByScope[$scope] = 0;
            }
        }
        return $transactionNestingLevelsByScope;
    }

    /**
     * Rolls back what the handler left open: a connection with no transaction before the job and one after it. With
     * savepoints off a nested rollBack() only decrements, so it is repeated until the level is 0; a rollback that
     * throws closes the connection (the server then ends the transaction). Never throws.
     *
     * @param Envelope $envelope The consumed message
     * @param int[] $transactionNestingLevelsBeforeJob Nesting level by scope before the handler ran
     * @return void
     */
    protected function rollBackTransactionsLeftOpenByJob(Envelope $envelope, array $transactionNestingLevelsBeforeJob): void
    {
        try {
            foreach (EntityManagerFactory::getInstantiatedScopes() as $scope) {
                if (($transactionNestingLevelsBeforeJob[$scope] ?? 0) > 0) {
                    continue;
                }
                $connection = EntityManagerFactory::getExistingInstance($scope)?->getConnection();
                if ($connection === null || $connection->getTransactionNestingLevel() === 0) {
                    continue;
                }
                // roll back first: a logging problem must never leave the transaction open
                try {
                    while ($connection->getTransactionNestingLevel() > 0) {
                        $connection->rollBack();
                    }
                } catch (Throwable) {
                    $connection->close();
                }
                try {
                    $handlerName = $this->firstAppMessageHandlerClass($envelope) ?? $envelope->getMessage()::class;
                    DDDService::instance()->getLogger()->warning(
                        "FreshWorkerStateMiddleware: rolled back a transaction left open by $handlerName (scope $scope)"
                    );
                } catch (Throwable) {
                    // fail-soft, see the class docblock
                }
            }
        } catch (Throwable) {
            // fail-soft, see the class docblock
        }
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
