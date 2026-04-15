<?php

declare(strict_types=1);

namespace DDD;

use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Modules\DDDModule;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\CompilerPasses\ModuleCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class DDDBundle extends Bundle
{
    protected static ContainerInterface $defaultContainer;

    public function boot(): void
    {
        // Entferne die Umgebungsvariable APP_RUNTIME_OPTIONS
        unset($_ENV['APP_RUNTIME_OPTIONS']);
        putenv('APP_RUNTIME_OPTIONS');

        $projectDirectory = $this->container->getParameterBag()->get('kernel.project_dir');
        if (!defined('APP_ROOT_DIR')) {
            define('APP_ROOT_DIR', $projectDirectory);
        }
        self::$defaultContainer = $this->container;

        // Load application config (app-level: highest priority)
        Config::addConfigDirectory(DDDService::instance()->getRootDir() . '/config/app');

        // Load DDD framework config (module-level: lower priority than app configs)
        Config::addConfigDirectory(DDDService::instance()->getFrameworkRootDir() . '/config/app', isModule: true);

        // Load module configs (module-level: lower priority than app configs)
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.project_dir', $projectDirectory);
        foreach (ModuleCompilerPass::discoverModules($containerBuilder) as $moduleClass) {
            /** @var DDDModule $moduleClass */
            $configPath = $moduleClass::getConfigPath();
            if ($configPath !== null && is_dir($configPath)) {
                Config::addConfigDirectory($configPath, isModule: true);
            }
        }

        parent::boot();
    }

    public static function getContainer(): ContainerInterface
    {
        return self::$defaultContainer;
    }
}