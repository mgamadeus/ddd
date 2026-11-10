<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses;

use DDD\Domain\Common\Services\WorkerTransportsService;
use DDD\Symfony\CompilerPasses\WorkerTransportsPass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class WorkerTransportsPassTest extends TestCase
{
    public function testReceiversFailureTransportsDsnsAndRoutingReachTheService(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('messenger.transport.send_mail', (new Definition(stdClass::class))
            ->setArguments(['amqp://broker/%2f/send_mail', ['transport_name' => 'send_mail']])
            ->addTag('messenger.receiver', ['alias' => 'send_mail', 'is_failure_transport' => false]));
        $container->setDefinition('messenger.transport.failed', (new Definition(stdClass::class))
            ->setArguments(['doctrine://default?queue_name=failed', []])
            ->addTag('messenger.receiver', ['alias' => 'failed', 'is_failure_transport' => true]));
        $container->setDefinition('messenger.transport.inline', (new Definition(stdClass::class))
            ->setArguments(['sync://', []])
            ->addTag('messenger.receiver', ['alias' => 'inline', 'is_failure_transport' => false]));
        $container->setDefinition('messenger.senders_locator', (new Definition(stdClass::class))
            ->setArguments([['App\\Mail\\SendMailMessage' => ['send_mail', 'inline']], null]));

        (new WorkerTransportsPass())->process($container);

        $definition = $container->getDefinition(WorkerTransportsService::class);
        $this->assertTrue($definition->isPublic());
        $workerTransportsService = new WorkerTransportsService(
            $definition->getArgument('$receiverNames'),
            $definition->getArgument('$failureTransportNames'),
            $definition->getArgument('$transportDsnsByName'),
            $definition->getArgument('$transportNamesByMessageClass'),
        );
        $this->assertSame(['send_mail', 'failed', 'inline'], $workerTransportsService->getReceiverNames());
        $this->assertSame(['failed'], $workerTransportsService->getFailureTransportNames());
        $this->assertSame(['send_mail'], $workerTransportsService->getConsumedTransportNames());
        $this->assertTrue($workerTransportsService->isSynchronousTransport('inline'));
        $this->assertSame(['send_mail', 'inline'], $workerTransportsService->getTransportNamesForMessageClass('App\\Mail\\SendMailMessage'));
        $this->assertSame([], $workerTransportsService->getTransportNamesForMessageClass('App\\Other'));
    }

    public function testAnApplicationWithoutTransportsGetsEmptyLists(): void
    {
        $container = new ContainerBuilder();
        (new WorkerTransportsPass())->process($container);
        $definition = $container->getDefinition(WorkerTransportsService::class);
        $this->assertSame([], $definition->getArgument('$receiverNames'));
        $this->assertSame([], $definition->getArgument('$transportNamesByMessageClass'));
    }

    public function testAnAutoRegisteredDefinitionIsReused(): void
    {
        $container = new ContainerBuilder();
        $autoRegistered = $container->register(WorkerTransportsService::class, WorkerTransportsService::class)->setAutowired(true);
        (new WorkerTransportsPass())->process($container);
        $this->assertSame($autoRegistered, $container->getDefinition(WorkerTransportsService::class));
        $this->assertTrue($autoRegistered->isPublic());
    }
}
