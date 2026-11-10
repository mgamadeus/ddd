<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Modules;

use DDD\Symfony\CompilerPasses\ModuleCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * The DDD modules of the running application, at runtime. The source is the container parameter
 * {@see ModuleCompilerPass::MODULES_PARAMETER}, which the {@see ModuleCompilerPass} writes at compile time — it holds
 * the modules Composer lists AND the kernel's additional ones
 * ({@see \DDD\Symfony\Kernels\DDDKernel::getAdditionalModuleClasses()}). A container compiled before the parameter
 * existed falls back to Composer's list ({@see ModuleCompilerPass::discoverModules()}).
 */
class DDDModules
{
    /**
     * @param ContainerInterface $container The compiled container of the application
     * @param string $projectDirectory The project directory whose `vendor/composer/installed.json` the fallback reads
     * @return string[] DDDModule subclass names, in registration order
     */
    public static function getModuleClasses(ContainerInterface $container, string $projectDirectory): array
    {
        try {
            if ($container->hasParameter(ModuleCompilerPass::MODULES_PARAMETER)) {
                return (array)$container->getParameter(ModuleCompilerPass::MODULES_PARAMETER);
            }
        } catch (Throwable) {
            // fall back to Composer's list below
        }
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.project_dir', $projectDirectory);
        return ModuleCompilerPass::discoverModules($containerBuilder);
    }
}
