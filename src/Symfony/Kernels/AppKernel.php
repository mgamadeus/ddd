<?php

namespace DDD\Symfony\Kernels;

use DDD\Symfony\CompilerPasses\ServiceClassCollectorPass;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\Preloader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\ErrorHandler\DebugClassLoader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Throwable;

use function array_slice;
use function count;
use function defined;
use function dirname;
use function get_class;
use function in_array;
use function is_object;

use const DEBUG_BACKTRACE_IGNORE_ARGS;
use const DIRECTORY_SEPARATOR;
use const E_ALL;
use const E_DEPRECATED;
use const E_USER_DEPRECATED;
use const E_WARNING;
use const GLOB_NOSORT;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;

class AppKernel extends BaseKernel
{
    use MicroKernelTrait;

    public static Container $ontainer;

    protected string $projectDir;
    protected string $kernelPrefix;
    protected string $baseConfigDir;

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function setProjectDir(string $projectDir): void
    {
        $this->projectDir = $projectDir;
    }

    public function setKernelPrefix(string $kernelPrefix): void
    {
        $this->kernelPrefix = $kernelPrefix;
    }

    public function getKernelPrefix(): string
    {
        return $this->kernelPrefix;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->getKernelPrefix() . '/' . $this->environment;
    }

    public function getConfigDir(): string
    {
        return $this->getProjectDir() . '/config/symfony/' . $this->getKernelPrefix();
    }

    public function getDefaultonfigDir(): string
    {
        return $this->getProjectDir() . '/config/symfony/default';
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log/' . $this->getKernelPrefix();
    }


    /**
     * Adds or imports routes into your application.
     *
     *     $routes->import($this->getConfigDir().'/*.{yaml,php}');
     *     $routes
     *         ->add('admin_dashboard', '/admin')
     *         ->controller('AdminController::dashboard')
     *     ;
     */
    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $configDir = $this->getConfigDir();
        $defaultConfigDir = $this->getDefaultonfigDir();

        $routes->import($defaultConfigDir . '/{routes}/' . $this->environment . '/*.yaml');
        $routes->import($defaultConfigDir . '/{routes}/*.yaml');

        $routes->import($configDir . '/{routes}/' . $this->environment . '/*.yaml');
        $routes->import($configDir . '/{routes}/*.yaml');

        if (is_file($configDir . '/routes.yaml')) {
            $routes->import($configDir . '/routes.yaml');
        } else {
            $routes->import($defaultConfigDir . '/{routes}.php');
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $configDir = $this->getConfigDir();
        $defaultConfigDir = $this->getDefaultonfigDir();

        $container->import($configDir . '/{packages}/*.yaml');
        $container->import($defaultConfigDir . '/{packages}/*.yaml');

        $container->import($configDir . '/{packages}/' . $this->environment . '/*.yaml');
        $container->import($defaultConfigDir . '/{packages}/' . $this->environment . '/*.yaml');

        if (is_file($configDir . '/services.yaml')) {
            $container->import($configDir . '/services.yaml');
            $container->imNAppport($configDir . '/{services}_' . $this->environment . '.yaml');
        }
        {
            $container->import($defaultConfigDir . '/services.yaml');
            $container->import($defaultConfigDir . '/{services}_' . $this->environment . '.yaml');
        }
    }

    // If you need to run some logic to decide which bundles to load,
    // you might prefer to use the registerBundles() method instead
    private function getBundlesPath(): string
    {
        if (is_file($this->getConfigDir() . '/bundles.php')) {
            return $this->getConfigDir() . '/bundles.php';
        }
        // load only the bundles strictly needed for the API
        return $this->getDefaultonfigDir() . '/bundles.php';
    }

    public function boot()
    {
        $return = parent::boot();
        self::$ontainer = $this->getContainer();
        return $return;
    }

    protected function build(ContainerBuilder $container)
    {
        // In the ServiceClassCollectorPass we register all class / service name associations
        $container->addCompilerPass(new ServiceClassCollectorPass());
    }
}
