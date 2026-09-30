<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Messenger\Middleware;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

class FreshStateProbeMessage
{
}

/**
 * An application's own handler base: it extends the framework reset with the application's process statics. The
 * override deliberately does NOT call parent, so the test needs no database or entity manager.
 */
class ApplicationHandlerBaseProbe extends AppMessageHandler
{
    public static int $resetCalls = 0;

    public static function resetWorkerStateForNewJob(): void
    {
        self::$resetCalls++;
    }
}

/** A handler that keeps warm state — the documented per-handler opt-out. */
class WarmStateHandlerProbe extends AppMessageHandler
{
    public const bool RESET_WORKER_STATE_BEFORE_JOB = false;

    public static int $resetCalls = 0;

    public static function resetWorkerStateForNewJob(): void
    {
        self::$resetCalls++;
    }
}

/** Not an AppMessageHandler — must be skipped when resolving the class whose reset runs. */
class PlainHandlerProbe
{
    public function __invoke(FreshStateProbeMessage $message): void
    {
    }
}

/** Records the class the middleware resolved, instead of running a reset. */
class RecordingFreshWorkerStateMiddleware extends FreshWorkerStateMiddleware
{
    public int $resetCalls = 0;

    public ?string $resolvedHandlerClass = null;

    protected function resetWorkerState(?string $handlerClass = null): void
    {
        $this->resetCalls++;
        $this->resolvedHandlerClass = $handlerClass;
    }
}

class FreshWorkerStateMiddlewareResetHandlerClassTest extends TestCase
{
    protected function setUp(): void
    {
        ApplicationHandlerBaseProbe::$resetCalls = 0;
        WarmStateHandlerProbe::$resetCalls = 0;
    }

    /** @param list<callable> $handlers */
    protected function locatorFor(array $handlers): HandlersLocatorInterface
    {
        return new class ($handlers) implements HandlersLocatorInterface {
            /** @param list<callable> $handlers */
            public function __construct(protected array $handlers)
            {
            }

            public function getHandlers(Envelope $envelope): iterable
            {
                foreach ($this->handlers as $handler) {
                    yield new HandlerDescriptor($handler);
                }
            }
        };
    }

    protected function passThrough(FreshWorkerStateMiddleware $middleware, Envelope $envelope): void
    {
        $terminator = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $envelope;
            }
        };
        // StackMiddleware::next() returns the element AFTER the offset, so the middleware under test is called directly
        $middleware->handle($envelope, new StackMiddleware([$middleware, $terminator]));
    }

    protected function receivedEnvelope(): Envelope
    {
        return new Envelope(new FreshStateProbeMessage(), [new ReceivedStamp('async')]);
    }

    /**
     * The point of the change: the reset runs on the message's own handler class, so an application handler base
     * that extends the reset is actually reached. Before, the framework base ran and the override was dead code.
     */
    public function testTheResetRunsOnTheMessagesOwnHandlerClass(): void
    {
        $middleware = new FreshWorkerStateMiddleware(
            $this->locatorFor([[ApplicationHandlerBaseProbe::class, 'resetWorkerStateForNewJob']])
        );

        $this->passThrough($middleware, $this->receivedEnvelope());

        $this->assertSame(1, ApplicationHandlerBaseProbe::$resetCalls);
    }

    /** A handler that is not an AppMessageHandler cannot carry the reset and is skipped. */
    public function testANonAppMessageHandlerIsSkippedInFavourOfTheAppMessageHandler(): void
    {
        $middleware = new RecordingFreshWorkerStateMiddleware(
            $this->locatorFor([
                new PlainHandlerProbe(),
                [ApplicationHandlerBaseProbe::class, 'resetWorkerStateForNewJob'],
            ])
        );

        $this->passThrough($middleware, $this->receivedEnvelope());

        $this->assertSame(ApplicationHandlerBaseProbe::class, $middleware->resolvedHandlerClass);
    }

    /** No resolvable AppMessageHandler class: the framework base runs, signalled by a null class. */
    public function testAMessageWithoutAnAppMessageHandlerResolvesToNull(): void
    {
        $middleware = new RecordingFreshWorkerStateMiddleware($this->locatorFor([new PlainHandlerProbe()]));

        $this->passThrough($middleware, $this->receivedEnvelope());

        $this->assertSame(1, $middleware->resetCalls);
        $this->assertNull($middleware->resolvedHandlerClass);
    }

    /** Without a locator there is nothing to resolve — the reset still runs, on the framework base. */
    public function testWithoutAHandlersLocatorTheResolvedClassIsNull(): void
    {
        $middleware = new RecordingFreshWorkerStateMiddleware(null);

        $this->passThrough($middleware, $this->receivedEnvelope());

        $this->assertSame(1, $middleware->resetCalls);
        $this->assertNull($middleware->resolvedHandlerClass);
    }

    /** The per-handler opt-out still wins: no reset at all, on no class. */
    public function testAHandlerOptingOutOfTheResetIsStillNotReset(): void
    {
        $middleware = new FreshWorkerStateMiddleware(
            $this->locatorFor([[WarmStateHandlerProbe::class, 'resetWorkerStateForNewJob']])
        );

        $this->passThrough($middleware, $this->receivedEnvelope());

        $this->assertSame(0, WarmStateHandlerProbe::$resetCalls);
    }

    /** A synchronous dispatch inside a request carries no ReceivedStamp and keeps its state. */
    public function testASynchronousDispatchIsNeverReset(): void
    {
        $middleware = new FreshWorkerStateMiddleware(
            $this->locatorFor([[ApplicationHandlerBaseProbe::class, 'resetWorkerStateForNewJob']])
        );

        $this->passThrough($middleware, new Envelope(new FreshStateProbeMessage()));

        $this->assertSame(0, ApplicationHandlerBaseProbe::$resetCalls);
    }
}
