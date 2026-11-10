<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Messenger\Middleware;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Symfony\CompilerPasses\FreshWorkerStateMiddlewarePass;
use DDD\Symfony\Kernels\DDDKernel;
use DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware;
use DDD\Symfony\Messenger\WorkerStateResetterInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

class WorkerStateResetterProbeMessage
{
}

/** Writes every call into one shared journal, so the test sees the order of the handler reset and the resetters. */
class WorkerStateResetJournal
{
    /** @var string[] */
    public static array $entries = [];
}

class JournalingHandlerBaseProbe extends AppMessageHandler
{
    public static function resetWorkerStateForNewJob(): void
    {
        WorkerStateResetJournal::$entries[] = 'handler';
    }
}

class JournalingWarmStateHandlerProbe extends AppMessageHandler
{
    public const bool RESET_WORKER_STATE_BEFORE_JOB = false;

    public static function resetWorkerStateForNewJob(): void
    {
        WorkerStateResetJournal::$entries[] = 'warm handler';
    }
}

/** A handler that extends NO framework base class. */
class JournalingPlainHandlerProbe
{
    public function __invoke(WorkerStateResetterProbeMessage $message): void
    {
    }
}

class JournalingWorkerStateResetter implements WorkerStateResetterInterface
{
    public function __construct(protected string $name)
    {
    }

    public function resetWorkerStateForNewJob(): void
    {
        WorkerStateResetJournal::$entries[] = $this->name;
    }
}

class ThrowingWorkerStateResetter implements WorkerStateResetterInterface
{
    public function resetWorkerStateForNewJob(): void
    {
        WorkerStateResetJournal::$entries[] = 'throwing';
        throw new RuntimeException('a broken resetter');
    }
}

/** Exposes the protected build() of the kernel, to read what it registers. */
class BuildExposingKernel extends DDDKernel
{
    public function buildForTest(ContainerBuilder $containerBuilder): void
    {
        $this->build($containerBuilder);
    }
}

class FreshWorkerStateMiddlewareWorkerStateResettersTest extends TestCase
{
    protected function setUp(): void
    {
        WorkerStateResetJournal::$entries = [];
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

    /** Runs the middleware with a terminator that records that the job itself ran. */
    protected function passThrough(FreshWorkerStateMiddleware $middleware, Envelope $envelope): void
    {
        $terminator = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                WorkerStateResetJournal::$entries[] = 'job';
                return $envelope;
            }
        };
        $middleware->handle($envelope, new StackMiddleware([$middleware, $terminator]));
    }

    protected function consumedEnvelope(): Envelope
    {
        return new Envelope(new WorkerStateResetterProbeMessage(), [new ReceivedStamp('async'), new ConsumedByWorkerStamp()]);
    }

    public function testTheResettersRunAfterTheHandlerResetInTheirOrderAndBeforeTheJob(): void
    {
        $middleware = new FreshWorkerStateMiddleware(
            $this->locatorFor([[JournalingHandlerBaseProbe::class, 'resetWorkerStateForNewJob']]),
            null,
            [new JournalingWorkerStateResetter('module'), new JournalingWorkerStateResetter('application')]
        );

        $this->passThrough($middleware, $this->consumedEnvelope());

        $this->assertSame(['handler', 'module', 'application', 'job'], WorkerStateResetJournal::$entries);
    }

    /** The point of the interface: the reset no longer depends on the handler's base class. */
    public function testTheResettersRunForAHandlerWithoutAFrameworkBaseClass(): void
    {
        $middleware = new class (
            $this->locatorFor([new JournalingPlainHandlerProbe()]),
            null,
            [new JournalingWorkerStateResetter('module')]
        ) extends FreshWorkerStateMiddleware {
            protected function resetWorkerState(?string $handlerClass = null): void
            {
                WorkerStateResetJournal::$entries[] = 'framework reset of ' . ($handlerClass ?? 'the base');
            }
        };

        $this->passThrough($middleware, $this->consumedEnvelope());

        $this->assertSame(['framework reset of the base', 'module', 'job'], WorkerStateResetJournal::$entries);
    }

    public function testAThrowingResetterStopsNeitherTheOtherResettersNorTheJob(): void
    {
        $middleware = new FreshWorkerStateMiddleware(
            $this->locatorFor([[JournalingHandlerBaseProbe::class, 'resetWorkerStateForNewJob']]),
            null,
            [new ThrowingWorkerStateResetter(), new JournalingWorkerStateResetter('after the broken one')]
        );

        $this->passThrough($middleware, $this->consumedEnvelope());

        $this->assertSame(['handler', 'throwing', 'after the broken one', 'job'], WorkerStateResetJournal::$entries);
    }

    /** A handler keeping warm state keeps the resetters' state as well. */
    public function testAHandlerOptingOutOfTheFreshStateSkipsTheResetters(): void
    {
        $middleware = new FreshWorkerStateMiddleware(
            $this->locatorFor([[JournalingWarmStateHandlerProbe::class, 'resetWorkerStateForNewJob']]),
            null,
            [new JournalingWorkerStateResetter('module')]
        );

        $this->passThrough($middleware, $this->consumedEnvelope());

        $this->assertSame(['job'], WorkerStateResetJournal::$entries);
    }

    public function testASynchronousOrSyncTransportDispatchRunsNoResetter(): void
    {
        $middleware = new FreshWorkerStateMiddleware(
            $this->locatorFor([[JournalingHandlerBaseProbe::class, 'resetWorkerStateForNewJob']]),
            null,
            [new JournalingWorkerStateResetter('module')]
        );

        $this->passThrough($middleware, new Envelope(new WorkerStateResetterProbeMessage()));
        $this->passThrough($middleware, new Envelope(new WorkerStateResetterProbeMessage(), [new ReceivedStamp('sync')]));

        $this->assertSame(['job', 'job'], WorkerStateResetJournal::$entries);
    }

    public function testThePassHandsTheTaggedResettersToTheMiddleware(): void
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->register('messenger.bus.default')->addTag('messenger.bus');
        $containerBuilder->setParameter('messenger.bus.default.middleware', [['id' => 'handle_message']]);

        (new FreshWorkerStateMiddlewarePass())->process($containerBuilder);

        $middlewareArgument = $containerBuilder
            ->getDefinition('messenger.bus.default' . FreshWorkerStateMiddlewarePass::MIDDLEWARE_ID_SUFFIX)
            ->getArgument(2);
        $this->assertInstanceOf(TaggedIteratorArgument::class, $middlewareArgument);
        $this->assertSame(WorkerStateResetterInterface::TAG, $middlewareArgument->getTag());
    }

    public function testTheKernelTagsEveryImplementationByAutoconfiguration(): void
    {
        $containerBuilder = new ContainerBuilder();

        (new BuildExposingKernel('test', false))->buildForTest($containerBuilder);

        $autoconfiguredInstanceof = $containerBuilder->getAutoconfiguredInstanceof();
        $this->assertArrayHasKey(WorkerStateResetterInterface::class, $autoconfiguredInstanceof);
        $this->assertTrue($autoconfiguredInstanceof[WorkerStateResetterInterface::class]->hasTag(WorkerStateResetterInterface::TAG));
    }
}
