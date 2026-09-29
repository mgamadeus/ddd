<?php

declare(strict_types=1);

namespace DDD\Symfony\Messenger\Middleware;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * A consumed {@see AppMessage} must be handled by the WORKSPACE that dispatched it. Several checkouts of one app
 * share a machine, a database and a broker; each has its own broker vhost, so a message normally never reaches a
 * consumer of another checkout. When one does — a vhost misconfigured, a queue name shared by mistake — this
 * middleware REFUSES it with an {@see UnrecoverableMessageHandlingException} naming both workspaces: no retry, the
 * message lands in the failure transport, the error is in the log. It never re-executes the message elsewhere: the
 * former reroute through the other workspace's console ran the whole job in a synchronous child process, blocked the
 * consuming worker for its duration and died with every restart of that worker.
 *
 * The comparison is by NAME ({@see AppMessage::$dispatchedFromEnvironment} against {@see DDDService::getWorkspaceName()}),
 * never by a filesystem check: a consumer may not have the other checkout mounted at all. A message without the stamp
 * (built outside {@see AppMessage::dispatch()}, or dispatched by a release that predates the stamp) passes. Only
 * received envelopes are checked; a synchronous dispatch inside a request stays in its own process anyway.
 *
 * Registered on every bus right after {@see FreshWorkerStateMiddleware} by
 * {@see \DDD\Symfony\CompilerPasses\FreshWorkerStateMiddlewarePass}; opt-out for a whole app with the parameter
 * `ddd.messenger.workspace_origin_guard: false`.
 */
class WorkspaceOriginGuardMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if ($envelope->last(ReceivedStamp::class) !== null && $message instanceof AppMessage) {
            $dispatchedFromEnvironment = $message->dispatchedFromEnvironment ?? null;
            $consumingEnvironment = $this->getConsumingWorkspaceName();
            if ($dispatchedFromEnvironment !== null && $dispatchedFromEnvironment !== $consumingEnvironment) {
                throw new UnrecoverableMessageHandlingException(sprintf(
                    '%s was dispatched from workspace "%s" but consumed in workspace "%s" (dispatched from directory %s): '
                    . 'the two share a queue they should not share — every workspace has its own broker vhost. '
                    . 'Refused; not re-executed anywhere.',
                    $message::class,
                    $dispatchedFromEnvironment,
                    $consumingEnvironment ?? '(none: production or a local machine)',
                    $message->dispatchedFromWorkspaceDir ?? '(unknown)'
                ));
            }
        }
        return $stack->next()->handle($envelope, $stack);
    }

    /** The consuming process's workspace name — the override point of a unit test. */
    protected function getConsumingWorkspaceName(): ?string
    {
        return DDDService::instance()->getWorkspaceName();
    }
}
