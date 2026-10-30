<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses;

use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Services\ServicesThrowErrorsResetter;
use DDD\Symfony\CompilerPasses\ServicesThrowErrorsResetterPass;
use DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;

class ThrowErrorsProbeAccountsService extends Service
{
    public ?string $lastLookedUpName = null;

    public static int $instancesCreated = 0;

    public function __construct()
    {
        self::$instancesCreated++;
    }

    /** A lookup in the shape of EntitiesService::find(): null on a miss, an exception when the flag is on. */
    public function findAccountName(string $accountName): ?string
    {
        $this->lastLookedUpName = $accountName;
        if ($this->throwErrors) {
            throw new \RuntimeException("Account $accountName not found");
        }
        return null;
    }
}

/** A service whose class declares the flag on: its reset goes back to true. */
class ThrowingByDefaultProbeService extends Service
{
    public bool $throwErrors = true;
}

/** Never fetched — the reset must not instantiate it. */
class NeverUsedProbeService extends Service
{
    public static int $instancesCreated = 0;

    public function __construct()
    {
        self::$instancesCreated++;
    }
}

/** An application service with its own reset() of a different meaning — the pass must not make it the reset method. */
class OwnResetProbeService extends Service
{
    public int $ownResetCalls = 0;

    public function reset(): void
    {
        $this->ownResetCalls++;
    }
}

class ProbeMessage
{
}

/**
 * A container-shared service keeps a flag one caller switched on: in a long-lived Messenger worker job N's
 * `throwErrors = true` made job N+1's lookups throw. The framework puts the flag of every instantiated service back
 * to its declared default between two consumed messages, and touches nothing else.
 */
class ServicesThrowErrorsResetterPassTest extends TestCase
{
    protected function setUp(): void
    {
        ThrowErrorsProbeAccountsService::$instancesCreated = 0;
        NeverUsedProbeService::$instancesCreated = 0;
    }

    /** The dumped (production) container, as the kernel builds it. */
    protected function buildDumpedContainer(): ContainerInterface
    {
        $containerBuilder = new ContainerBuilder();
        foreach ([ThrowErrorsProbeAccountsService::class, ThrowingByDefaultProbeService::class, NeverUsedProbeService::class, OwnResetProbeService::class] as $serviceClass) {
            $containerBuilder->setDefinition($serviceClass, (new Definition($serviceClass))->setPublic(true));
        }
        $containerBuilder->setDefinition(
            'probe.middleware',
            (new Definition(FreshWorkerStateMiddleware::class))
                ->setArgument(0, null)
                ->setArgument(1, new Reference(ServicesThrowErrorsResetter::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->setPublic(true)
        );
        $containerBuilder->addCompilerPass(new ServicesThrowErrorsResetterPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -16);
        $containerBuilder->compile();

        $containerClass = 'ThrowErrorsResetProbeContainer' . bin2hex(random_bytes(4));
        $dumpedContainerFile = sys_get_temp_dir() . "/$containerClass.php";
        file_put_contents($dumpedContainerFile, (new PhpDumper($containerBuilder))->dump(['class' => $containerClass]));
        require $dumpedContainerFile;
        unlink($dumpedContainerFile);
        return new $containerClass();
    }

    protected function consumeMessage(MiddlewareInterface $freshWorkerStateMiddleware, callable $job): void
    {
        $jobMiddleware = new class($job) implements MiddlewareInterface {
            public function __construct(protected $job)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->job)();
                return $envelope;
            }
        };
        $freshWorkerStateMiddleware->handle(
            new Envelope(new ProbeMessage(), [new ConsumedByWorkerStamp()]),
            // StackMiddleware::next() returns the element AFTER the offset: the middleware under test comes first
            new StackMiddleware([$freshWorkerStateMiddleware, $jobMiddleware])
        );
    }

    public function testAFlagJobALeftOnIsOffForJobB(): void
    {
        $container = $this->buildDumpedContainer();
        $freshWorkerStateMiddleware = new class(null, $container->get(ServicesThrowErrorsResetter::class)) extends FreshWorkerStateMiddleware {
            protected function resetWorkerState(?string $handlerClass = null): void
            {
                // the database part of the reset is not under test
            }
        };

        // job A: a checked lookup that never switches the flag back
        $this->consumeMessage($freshWorkerStateMiddleware, static function () use ($container): void {
            $container->get(ThrowErrorsProbeAccountsService::class)->throwErrors = true;
        });
        // job B: a lookup that expects null on a miss
        $accountNameSeenByJobB = 'not looked up';
        $this->consumeMessage($freshWorkerStateMiddleware, static function () use ($container, &$accountNameSeenByJobB): void {
            $accountNameSeenByJobB = $container->get(ThrowErrorsProbeAccountsService::class)->findAccountName('no-such-account');
        });

        $this->assertNull($accountNameSeenByJobB);
        $this->assertSame(1, ThrowErrorsProbeAccountsService::$instancesCreated, 'the job services are the shared instance');
    }

    public function testTheResetTouchesOnlyTheFlagOfInstantiatedServices(): void
    {
        $container = $this->buildDumpedContainer();
        $accountsService = $container->get(ThrowErrorsProbeAccountsService::class);
        $accountsService->findAccountName('alice');
        $accountsService->throwErrors = true;
        $throwingByDefaultService = $container->get(ThrowingByDefaultProbeService::class);
        $throwingByDefaultService->throwErrors = false;
        $ownResetService = $container->get(OwnResetProbeService::class);

        $resetServicesCount = $container->get(ServicesThrowErrorsResetter::class)->resetThrowErrorsOfInstantiatedServices();

        $this->assertSame(3, $resetServicesCount);
        $this->assertFalse($accountsService->throwErrors);
        $this->assertSame('alice', $accountsService->lastLookedUpName, 'other service state stays');
        $this->assertTrue($throwingByDefaultService->throwErrors, 'the declared default of the class');
        $this->assertSame(0, NeverUsedProbeService::$instancesCreated, 'a reset never creates a service');
        $this->assertSame(0, $ownResetService->ownResetCalls, 'an own reset() is not the reset method');
    }

    public function testSymfonysServicesResetterCallsTheDedicatedMethod(): void
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setDefinition(OwnResetProbeService::class, new Definition(OwnResetProbeService::class));
        $containerBuilder->setDefinition('probe.not_a_service', new Definition(ProbeMessage::class));
        (new ServicesThrowErrorsResetterPass())->process($containerBuilder);

        $this->assertSame(
            [['method' => ServicesThrowErrorsResetterPass::RESET_METHOD]],
            $containerBuilder->getDefinition(OwnResetProbeService::class)->getTag('kernel.reset')
        );
        $this->assertSame([], $containerBuilder->getDefinition('probe.not_a_service')->getTag('kernel.reset'));
    }

    public function testTheScopedApiRestoresTheValueBefore(): void
    {
        $accountsService = new ThrowErrorsProbeAccountsService();

        $throwErrorsBefore = $accountsService->enableThrowErrors();
        try {
            $this->assertTrue($accountsService->throwErrors);
        } finally {
            $accountsService->restoreThrowErrors($throwErrorsBefore);
        }
        $this->assertFalse($accountsService->throwErrors);

        $throwingByDefaultService = new ThrowingByDefaultProbeService();
        $throwErrorsBefore = $throwingByDefaultService->disableThrowErrors();
        $this->assertFalse($throwingByDefaultService->throwErrors);
        $throwingByDefaultService->restoreThrowErrors($throwErrorsBefore);
        $this->assertTrue($throwingByDefaultService->throwErrors);
    }
}
