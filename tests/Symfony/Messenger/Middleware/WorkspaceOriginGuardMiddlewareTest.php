<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Messenger\Middleware;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Symfony\Messenger\Middleware\WorkspaceOriginGuardMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

class OriginProbeMessage extends AppMessage
{
    public static string $messageHandler = 'OriginProbeHandler';
}

/** The consuming process's workspace is the documented override point. */
class WorkspaceOriginGuardProbe extends WorkspaceOriginGuardMiddleware
{
    public function __construct(protected ?string $consumingWorkspaceName)
    {
    }

    protected function getConsumingWorkspaceName(): ?string
    {
        return $this->consumingWorkspaceName;
    }
}

class WorkspaceOriginGuardMiddlewareTest extends TestCase
{
    protected int $handled = 0;

    protected function passThrough(WorkspaceOriginGuardMiddleware $middleware, Envelope $envelope): void
    {
        $terminator = new class ($this->handled) implements MiddlewareInterface {
            public function __construct(public int &$handled)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->handled++;
                return $envelope;
            }
        };
        // StackMiddleware::next() returns the element AFTER the offset, so the middleware under test is called directly
        $middleware->handle($envelope, new StackMiddleware([$middleware, $terminator]));
    }

    protected function messageFrom(?string $environment): OriginProbeMessage
    {
        $message = new OriginProbeMessage();
        $message->dispatchedFromEnvironment = $environment;
        $message->dispatchedFromWorkspaceDir = $environment === null ? null : "/var/www/dev-workspaces/$environment/app";
        return $message;
    }

    public function testAMessageFromAnotherWorkspaceIsRefused(): void
    {
        $envelope = new Envelope($this->messageFrom('prj6'), [new ReceivedStamp('async')]);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessageMatches('/dispatched from workspace "prj6".*consumed in workspace "mgn"/s');
        $this->passThrough(new WorkspaceOriginGuardProbe('mgn'), $envelope);
    }

    public function testProductionRefusesAWorkspaceMessage(): void
    {
        // the reaper case: production consuming a dev workspace's message must not run it on production code
        $envelope = new Envelope($this->messageFrom('prj6'), [new ReceivedStamp('async')]);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessageMatches('/production or a local machine/');
        $this->passThrough(new WorkspaceOriginGuardProbe(null), $envelope);
    }

    public function testAMessageFromTheOwnWorkspacePasses(): void
    {
        $this->passThrough(new WorkspaceOriginGuardProbe('mgn'), new Envelope($this->messageFrom('mgn'), [new ReceivedStamp('async')]));
        $this->assertSame(1, $this->handled);
    }

    public function testAMessageWithoutAnOriginStampPasses(): void
    {
        // built outside dispatch(), or dispatched by a release older than the stamp — refusing those would break the upgrade
        $this->passThrough(new WorkspaceOriginGuardProbe('mgn'), new Envelope($this->messageFrom(null), [new ReceivedStamp('async')]));
        $this->assertSame(1, $this->handled);
    }

    public function testASynchronousDispatchIsNotChecked(): void
    {
        // no ReceivedStamp: the message never left this process, whatever its origin stamp says
        $this->passThrough(new WorkspaceOriginGuardProbe('mgn'), new Envelope($this->messageFrom('prj6')));
        $this->assertSame(1, $this->handled);
    }

    public function testANonAppMessagePasses(): void
    {
        $this->passThrough(new WorkspaceOriginGuardProbe('mgn'), new Envelope(new \stdClass(), [new ReceivedStamp('async')]));
        $this->assertSame(1, $this->handled);
    }
}
