<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses;

use DDD\Tests\Symfony\CompilerPasses\Fixtures\ResettingModuleSubscriber;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\TagApplyingModuleCompilerPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

/** A module service gets the tags the container's autoconfiguration assigns to its interfaces */
class ModuleCompilerPassTest extends TestCase
{
    public function testAModuleServiceGetsTheAutoconfiguredTagsOfItsInterfaces(): void
    {
        $container = new ContainerBuilder();
        $container->registerForAutoconfiguration(ResetInterface::class)->addTag('kernel.reset', ['method' => 'reset']);
        $container->registerForAutoconfiguration(EventSubscriberInterface::class)->addTag('kernel.event_subscriber');
        $container->registerForAutoconfiguration(\Countable::class)->addTag('unrelated.tag');
        $definition = new Definition(ResettingModuleSubscriber::class);

        (new TagApplyingModuleCompilerPass())->applyTags($container, $definition, ResettingModuleSubscriber::class);

        self::assertSame([['method' => 'reset']], $definition->getTag('kernel.reset'));
        self::assertTrue($definition->hasTag('kernel.event_subscriber'));
        self::assertFalse($definition->hasTag('unrelated.tag'));
    }

    public function testATagTheDefinitionAlreadyCarriesIsKept(): void
    {
        $container = new ContainerBuilder();
        $container->registerForAutoconfiguration(ResetInterface::class)->addTag('kernel.reset', ['method' => 'reset']);
        $definition = (new Definition(ResettingModuleSubscriber::class))->addTag('kernel.reset', ['method' => 'resetOwnState']);

        (new TagApplyingModuleCompilerPass())->applyTags($container, $definition, ResettingModuleSubscriber::class);

        self::assertSame([['method' => 'resetOwnState']], $definition->getTag('kernel.reset'));
    }
}
