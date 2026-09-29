<?php

declare(strict_types=1);

namespace DDD;

use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Modules\DDDModule;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\CompilerPasses\ModuleCompilerPass;
use DDD\Infrastructure\Services\DDDService as DDDServiceForDefaults;
use DDD\Symfony\DependencyInjection\EnvVarProcessors\WorkspaceVhostEnvVarProcessor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
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

    /**
     * Registers what the framework itself provides to the container: the `%env(workspace_vhost:…)%` processor and the
     * two parameters that steer it. Both parameters are only set when the application has NOT set them, so an app's
     * own value always wins.
     *
     * @param ContainerBuilder $container
     * @return void
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (!$container->hasParameter(WorkspaceVhostEnvVarProcessor::ENABLED_PARAMETER)) {
            $container->setParameter(WorkspaceVhostEnvVarProcessor::ENABLED_PARAMETER, true);
        }
        if (!$container->hasParameter(WorkspaceVhostEnvVarProcessor::PATH_SEGMENT_PARAMETER)) {
            // the constant is the DEFAULT of the parameter, never a second source of truth
            $container->setParameter(
                WorkspaceVhostEnvVarProcessor::PATH_SEGMENT_PARAMETER,
                DDDServiceForDefaults::WORKSPACES_PATH_SEGMENT
            );
        }

        $processor = new Definition(WorkspaceVhostEnvVarProcessor::class);
        $processor->setArguments([
            '%kernel.project_dir%',
            '%' . WorkspaceVhostEnvVarProcessor::ENABLED_PARAMETER . '%',
            '%' . WorkspaceVhostEnvVarProcessor::PATH_SEGMENT_PARAMETER . '%',
        ]);
        // Symfony finds an env var processor by this tag alone
        $processor->addTag('container.env_var_processor');
        $container->setDefinition(WorkspaceVhostEnvVarProcessor::class, $processor);
    }

    public static function getContainer(): ContainerInterface
    {
        return self::$defaultContainer;
    }
}