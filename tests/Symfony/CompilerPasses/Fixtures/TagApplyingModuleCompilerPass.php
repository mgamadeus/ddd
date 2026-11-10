<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures;

use DDD\Symfony\CompilerPasses\ModuleCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** Exposes the tag application and the service registration of ModuleCompilerPass */
class TagApplyingModuleCompilerPass extends ModuleCompilerPass
{
    public function applyTags(ContainerBuilder $container, Definition $definition, string $className): void
    {
        $this->applyAutoconfiguredInstanceofTags($container, $definition, $className);
    }

    public function registerServicesOfModule(ContainerBuilder $container, string $moduleClass): void
    {
        $this->registerModuleServices($container, $moduleClass);
    }
}
