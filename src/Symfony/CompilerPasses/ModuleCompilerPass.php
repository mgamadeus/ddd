<?php

declare(strict_types=1);

namespace DDD\Symfony\CompilerPasses;

use DDD\Infrastructure\Modules\DDDModule;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\Finder\Finder;
use ReflectionAttribute;
use ReflectionClass;
use Throwable;

/**
 * Discovers DDD modules from installed Composer packages and registers
 * their classes as autowired services in the container.
 *
 * Modules declare themselves via composer.json extra.ddd-module pointing
 * to a class extending DDDModule. A kernel adds modules Composer does not list (a module's own test kernel, a module
 * installed by path) through {@see \DDD\Symfony\Kernels\DDDKernel::getAdditionalModuleClasses()}, which the kernel
 * hands over in the parameter {@see self::ADDITIONAL_MODULES_PARAMETER}.
 *
 * The final module list is kept in the container parameter {@see self::MODULES_PARAMETER} (read at runtime through
 * {@see \DDD\Infrastructure\Modules\DDDModules::getModuleClasses()}).
 *
 * Registered by the kernel with priority {@see self::PASS_PRIORITY} of the before-optimization phase, i.e. BEFORE
 * Symfony's autoconfiguration passes (priority 100: `#[AsMessageHandler]`, `#[AsCommand]`, `#[AutoconfigureTag]`,
 * `registerForAutoconfiguration()` / `_instanceof`). A module service is therefore autoconfigured exactly like an
 * application service from a resource block: its handlers, commands and tagged services reach the container with no
 * application wiring.
 */
class ModuleCompilerPass implements CompilerPassInterface
{
    /** @var string Container parameter: the module classes this container registered, in registration order */
    public const string MODULES_PARAMETER = 'ddd.modules';

    /** @var string Container parameter: module classes a kernel adds to the ones Composer lists */
    public const string ADDITIONAL_MODULES_PARAMETER = 'ddd.additional_modules';

    /** @var int Priority in the before-optimization phase: above Symfony's autoconfiguration passes (100) */
    public const int PASS_PRIORITY = 110;

    /** @var string[]|null Cached list of discovered module class names */
    protected static ?array $discoveredModules = null;

    /** @var string[] The interfaces found in the modules' source paths during {@see process()} */
    protected array $moduleInterfaceNames = [];

    public function process(ContainerBuilder $container): void
    {
        $modules = self::resolveModules($container);
        $container->setParameter(self::MODULES_PARAMETER, $modules);

        $this->moduleInterfaceNames = [];
        foreach ($modules as $moduleClass) {
            $this->registerModuleServices($container, $moduleClass);
        }
        $this->registerAliasesForSinglyImplementedModuleInterfaces($container);
    }

    /**
     * The modules Composer lists ({@see discoverModules()}) followed by the kernel's additional ones
     * ({@see self::ADDITIONAL_MODULES_PARAMETER}); a class listed twice is kept once, at its first position. An
     * additional entry that is no existing, concrete {@see DDDModule} subclass is skipped.
     *
     * @param ContainerBuilder $container The container being compiled
     * @return string[] DDDModule subclass names
     */
    public static function resolveModules(ContainerBuilder $container): array
    {
        $modules = self::discoverModules($container);
        $additionalModules = $container->hasParameter(self::ADDITIONAL_MODULES_PARAMETER)
            ? (array)$container->getParameter(self::ADDITIONAL_MODULES_PARAMETER)
            : [];
        foreach ($additionalModules as $additionalModuleClass) {
            if (!is_string($additionalModuleClass) || in_array($additionalModuleClass, $modules, true)) {
                continue;
            }
            if (!self::isConcreteModuleClass($additionalModuleClass)) {
                continue;
            }
            $modules[] = $additionalModuleClass;
        }
        return $modules;
    }

    /**
     * @param string $moduleClass A class name
     * @return bool Whether it names an existing, non-abstract subclass of {@see DDDModule}
     */
    protected static function isConcreteModuleClass(string $moduleClass): bool
    {
        try {
            return class_exists($moduleClass)
                && is_subclass_of($moduleClass, DDDModule::class)
                && !(new ReflectionClass($moduleClass))->isAbstract();
        } catch (Throwable) {
            return false;
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
        $lazyNamespaces = $moduleClass::getLazyServiceNamespaces();

        $finder = new Finder();
        $finder->files()->name('*.php')->in($sourcePath);

        foreach ($finder as $file) {
            $className = $this->extractClassName($file->getRealPath());
            $interfaceName = $className === null ? $this->extractInterfaceName($file->getRealPath()) : null;
            if ($className === null && $interfaceName === null) {
                continue;
            }
            $className ??= $interfaceName;

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

            if ($interfaceName !== null) {
                $this->registerModuleInterface($container, $interfaceName);
                continue;
            }

            if ($this->registerAsExcludedWhenNoService($container, $className)) {
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
            // Make lazy if namespace matches
            foreach ($lazyNamespaces as $lazyNamespace) {
                if (str_starts_with($className, $lazyNamespace)) {
                    $definition->setLazy(true);
                    break;
                }
            }

            $this->applyAutoconfiguredInstanceofTags($container, $definition, $className);
            $container->setDefinition($className, $definition);
            $this->registerAsAliasAttributes($container, $className);
        }
    }

    /**
     * The `#[AsAlias]` attributes of a module service, as Symfony's resource loader applies them to an application
     * class: the alias id is the attribute's id, or the class's only interface; `public` and `when` (environments) as
     * declared. An alias or service of that id the application defined itself wins (an application override).
     *
     * @param ContainerBuilder $container The container being compiled
     * @param string $className The module service's class
     * @throws LogicException When the attribute names no id and the class implements no or several interfaces
     */
    protected function registerAsAliasAttributes(ContainerBuilder $container, string $className): void
    {
        if (!class_exists($className)) {
            return;
        }
        $asAliasAttributes = (new ReflectionClass($className))->getAttributes(AsAlias::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($asAliasAttributes === []) {
            return;
        }
        $implementedInterfaces = array_values(class_implements($className));
        $environment = $container->hasParameter('kernel.environment') ? $container->getParameter('kernel.environment') : null;
        foreach ($asAliasAttributes as $asAliasAttribute) {
            /** @var AsAlias $asAlias */
            $asAlias = $asAliasAttribute->newInstance();
            $aliasId = $asAlias->id ?? (count($implementedInterfaces) === 1 ? $implementedInterfaces[0] : null);
            if ($aliasId === null) {
                throw new LogicException(sprintf('Alias cannot be automatically determined for class "%s". Name the alias in its #[AsAlias] attribute.', $className));
            }
            if ($asAlias->when !== [] && !in_array($environment, $asAlias->when, true)) {
                continue;
            }
            if ($container->has($aliasId)) {
                continue;
            }
            $container->setAlias($aliasId, $className)->setPublic($asAlias->public);
        }
    }

    /**
     * Treats a module class the way Symfony's resource loader (`FileLoader::registerClasses()`) treats an application
     * class of a resource block, so a module needs no application wiring to keep a non-service out of the container:
     * - a class carrying Symfony's `#[Exclude]` attribute (plumbing built with `new`, e.g. a class whose constructor
     *   takes a console output) is registered as an abstract definition tagged `container.excluded`, never as an
     *   autowired service — public or not, autowiring its constructor would fail the compilation;
     * - an abstract class is registered as the abstract, autoconfigured definition `.abstract.<class>` tagged
     *   `container.excluded` (the loader's id), never as an instantiable service under its own id; being
     *   autoconfigured, its `#[Autoconfigure…]` attributes reach the services that extend it.
     * Both kinds are removed with the other abstract definitions before the container is dumped.
     *
     * @param ContainerBuilder $container The container being compiled
     * @param string $className The module class found in the module's source path
     * @return bool Whether the class was registered as excluded (true) or is a service candidate (false)
     */
    protected function registerAsExcludedWhenNoService(ContainerBuilder $container, string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }
        $reflectionClass = new ReflectionClass($className);
        if ($reflectionClass->getAttributes(Exclude::class) !== []) {
            $container->register($className, $className)
                ->setAbstract(true)
                ->addTag('container.excluded', ['source' => 'because the class carries #[Exclude]']);
            return true;
        }
        if ($reflectionClass->isAbstract()) {
            $abstractServiceId = '.abstract.' . $className;
            if (!$container->hasDefinition($abstractServiceId)) {
                $container->register($abstractServiceId, $className)
                    ->setAbstract(true)
                    ->setAutoconfigured(true)
                    ->addTag('container.excluded', ['source' => 'because the class is abstract']);
            }
            return true;
        }
        return false;
    }

    /**
     * A module interface becomes the abstract, autoconfigured definition `.abstract.<interface>` — the definition
     * Symfony's resource loader gives an interface. Symfony reads an interface's `#[AutoconfigureTag]` /
     * `#[Autoconfigure]` only from such a definition (`RegisterAutoconfigureAttributesPass`), so without it a module
     * interface's tag never reaches the services that implement it, in the module or in the application.
     *
     * @param ContainerBuilder $container The container being compiled
     * @param string $interfaceName The interface found in a module's source path
     */
    protected function registerModuleInterface(ContainerBuilder $container, string $interfaceName): void
    {
        if (!interface_exists($interfaceName)) {
            return;
        }
        $this->moduleInterfaceNames[] = $interfaceName;
        $abstractServiceId = '.abstract.' . $interfaceName;
        if ($container->hasDefinition($abstractServiceId)) {
            return;
        }
        $container->register($abstractServiceId, $interfaceName)
            ->setAbstract(true)
            ->setAutoconfigured(true)
            ->addTag('container.excluded', ['source' => 'because the class is abstract']);
    }

    /**
     * The resource loader's alias of a singly implemented interface (`FileLoader::registerAliasesForSinglyImplementedInterfaces()`)
     * for the module interfaces: a module interface implemented by exactly one class registered as a service under its
     * own class name (an application resource block or a module) gets a private alias to that service, unless a
     * service or alias of the interface's name exists. Autowiring by the interface type then resolves as it does for
     * an application interface.
     *
     * @param ContainerBuilder $container The container being compiled
     */
    protected function registerAliasesForSinglyImplementedModuleInterfaces(ContainerBuilder $container): void
    {
        if ($this->moduleInterfaceNames === []) {
            return;
        }
        $implementingClassesByInterface = array_fill_keys($this->moduleInterfaceNames, []);
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $className = $definition->getClass();
            if ($className !== $serviceId || $definition->isAbstract() || $definition->isSynthetic() || !class_exists($className)) {
                continue;
            }
            foreach (class_implements($className) as $implementedInterface) {
                if (isset($implementingClassesByInterface[$implementedInterface])) {
                    $implementingClassesByInterface[$implementedInterface][$className] = true;
                }
            }
        }
        foreach ($implementingClassesByInterface as $interfaceName => $implementingClasses) {
            if (count($implementingClasses) !== 1 || $container->has($interfaceName)) {
                continue;
            }
            $container->setAlias($interfaceName, array_key_first($implementingClasses));
        }
    }

    /**
     * Gives a module service the tags the container's autoconfiguration assigns to its interfaces and parents
     * (`EventSubscriberInterface` → `kernel.event_subscriber`, `ResetInterface` → `kernel.reset`, …). Needed only when
     * the pass runs AFTER Symfony resolved the autoconfiguration (`ResolveInstanceofConditionalsPass`, priority 100) —
     * a kernel registering it with a priority below {@see self::PASS_PRIORITY}. Registered at
     * {@see self::PASS_PRIORITY} (the {@see \DDD\Symfony\Kernels\DDDKernel} default), Symfony applies interface AND
     * attribute autoconfiguration itself and skips the identical tags this method already added.
     */
    protected function applyAutoconfiguredInstanceofTags(
        ContainerBuilder $container,
        Definition $definition,
        string $className
    ): void {
        if (!class_exists($className)) {
            return;
        }
        foreach ($container->getAutoconfiguredInstanceof() as $autoconfiguredType => $autoconfiguredDefinition) {
            if (!is_a($className, $autoconfiguredType, true)) {
                continue;
            }
            foreach ($autoconfiguredDefinition->getTags() as $tagName => $tagAttributesList) {
                if ($definition->hasTag($tagName)) {
                    continue;
                }
                foreach ($tagAttributesList as $tagAttributes) {
                    $definition->addTag($tagName, $tagAttributes);
                }
            }
        }
    }

    /**
     * The fully qualified name of the interface a PHP file declares, or null when it declares none.
     */
    protected function extractInterfaceName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }
        if (!preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatches)
            || !preg_match('/^interface\s+(\w+)/m', $contents, $interfaceMatches)) {
            return null;
        }
        return trim($namespaceMatches[1]) . '\\' . $interfaceMatches[1];
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
