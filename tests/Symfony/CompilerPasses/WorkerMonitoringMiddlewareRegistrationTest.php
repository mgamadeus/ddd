<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses;

use DDD\Symfony\CompilerPasses\FreshWorkerStateMiddlewarePass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class WorkerMonitoringMiddlewareRegistrationTest extends TestCase
{
    protected function createContainerWithOneBus(): ContainerBuilder
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->register('messenger.bus.default', stdClass::class)->addTag('messenger.bus');
        $containerBuilder->setParameter('messenger.bus.default.middleware', [['id' => 'app_middleware']]);
        return $containerBuilder;
    }

    public function testTheMonitoringRunsBetweenFreshStateAndTheOriginGuard(): void
    {
        $containerBuilder = $this->createContainerWithOneBus();

        (new FreshWorkerStateMiddlewarePass())->process($containerBuilder);

        self::assertSame(
            [
                'messenger.bus.default' . FreshWorkerStateMiddlewarePass::MIDDLEWARE_ID_SUFFIX,
                'messenger.bus.default' . FreshWorkerStateMiddlewarePass::WORKER_MONITORING_MIDDLEWARE_ID_SUFFIX,
                'messenger.bus.default' . FreshWorkerStateMiddlewarePass::ORIGIN_GUARD_MIDDLEWARE_ID_SUFFIX,
                'app_middleware',
            ],
            array_column($containerBuilder->getParameter('messenger.bus.default.middleware'), 'id')
        );
    }

    public function testTheParameterRemovesTheMonitoringMiddleware(): void
    {
        $containerBuilder = $this->createContainerWithOneBus();
        $containerBuilder->setParameter(FreshWorkerStateMiddlewarePass::WORKER_MONITORING_ENABLED_PARAMETER, false);

        (new FreshWorkerStateMiddlewarePass())->process($containerBuilder);

        self::assertNotContains(
            'messenger.bus.default' . FreshWorkerStateMiddlewarePass::WORKER_MONITORING_MIDDLEWARE_ID_SUFFIX,
            array_column($containerBuilder->getParameter('messenger.bus.default.middleware'), 'id')
        );
    }
}
