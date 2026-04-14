<?php

declare(strict_types=1);

namespace DDD\Symfony\CompilerPasses;

use DDD\Infrastructure\Modules\DDDModule;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Finder\Finder;

/**
 * Discovers DDD modules from installed Composer packages and registers
 * their classes as autowired services in the container.
 *
 * Modules declare themselves via composer.json extra.ddd-module pointing
 * to a class extending DDDModule.
 */
class ModuleCompilerPass implements CompilerPassInterface
{
    /** @var string[]|null Cached list of discovered module class names */
    protected static ?array $discoveredModules = null;

    public function process(ContainerBuilder $container): void
    {
        $modules = self::discoverModules($container);

        foreach ($modules as $moduleClass) {
            $this->registerModuleServices($container, $moduleClass);
        }
    }

    /**
     * Discovers modules by scanning installed Composer packages for the "ddd-module" extra key.
     *
     * @return string[] Array of DDDModule subclass names
     */
    public static function discoverModules(ContainerBuilder $container): array
    {
        if (self::$discoveredModules !== null) {
            return self::$discoveredModules;
        }

        self::$discoveredModules = [];

        $projectDir = $container->getParameter('kernel.project_dir');
        $installedPath = $projectDir . '/vendor/composer/installed.json';

        if (!file_exists($installedPath)) {
            return self::$discoveredModules;
        }

        $installed = json_decode(file_get_contents($installedPath), true);
        $packages = $installed['packages'] ?? $installed;

        foreach ($packages as $package) {
            $moduleClass = $package['extra']['ddd-module'] ?? null;
            if ($moduleClass === null) {
                continue;
            }
            if (!class_exists($moduleClass)) {
                continue;
            }
            if (!is_subclass_of($moduleClass, DDDModule::class)) {
                continue;
            }
            self::$discoveredModules[] = $moduleClass;
        }

        return self::$discoveredModules;
    }

    /**
     * Registers all PHP classes from a module's source path as autowired services.
     */
    protected function registerModuleServices(ContainerBuilder $container, string $moduleClass): void
    {
        /** @var DDDModule $moduleClass */
        $sourcePath = $moduleClass::getSourcePath();
        if (!is_dir($sourcePath)) {
            return;
        }

        $excludePatterns = $moduleClass::getExcludePatterns();
        $publicNamespaces = $moduleClass::getPublicServiceNamespaces();

        $finder = new Finder();
        $finder->files()->name('*.php')->in($sourcePath);

        foreach ($finder as $file) {
            $className = $this->extractClassName($file->getRealPath());
            if ($className === null) {
                continue;
            }

            // Skip excluded patterns
            $skip = false;
            foreach ($excludePatterns as $pattern) {
                if (str_contains($className, '\\' . $pattern . '\\')) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            // Don't re-register if already defined (e.g. by app override in services.yaml)
            if ($container->hasDefinition($className) || $container->hasAlias($className)) {
                continue;
            }

            $definition = new Definition($className);
            $definition->setAutowired(true);
            $definition->setAutoconfigured(true);

            // Make public if namespace matches
            foreach ($publicNamespaces as $ns) {
                if (str_starts_with($className, $ns)) {
                    $definition->setPublic(true);
                    break;
                }
            }

            $container->setDefinition($className, $definition);
        }
    }

    /**
     * Extracts the fully qualified class name from a PHP file by parsing namespace and class declarations.
     */
    protected function extractClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = trim($matches[1]);
        }

        // Match class, enum, or trait declarations (not interfaces or abstract-only)
        if (preg_match('/^(?:abstract\s+)?(?:final\s+)?(?:readonly\s+)?(?:class|enum)\s+(\w+)/m', $contents, $matches)) {
            $class = $matches[1];
        }

        if ($namespace === null || $class === null) {
            return null;
        }

        return $namespace . '\\' . $class;
    }
}
