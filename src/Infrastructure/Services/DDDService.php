<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

use DDD\DDDBundle;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\Virtual\VirtualEntityRegistry;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\ClassFinder;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Services\RequestService;
use DDD\Symfony\Kernels\DDDKernel;
use Exception;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Twig\Environment;

class DDDService
{
    public const FRAMEWORK_ROOT_NAMESPACE = 'DDD';

    public const APP_ROOT_NAMESPACE = 'App';

    public const ENV_DEV = 'dev';

    public const ENV_PROD = 'prod';

    /** @var bool If true, the service itself will throw errors */
    public bool $throwErrors = false;

    /** @var bool Controls caching behaviour, app components can access this variable in order to decide if they apply caching or not */
    public static bool $noCache = false;

    protected static $cachesSnapshotSet = false;

    protected static $entityRightsRestrictionSnapshotSet = false;

    protected static ?string $frameworkDir = null;

    protected static $cacheStates = [
        'doctrineRegistry' => true,
        'virtualRegistry' => true,
        'app' => true,
        'classFinder' => true
    ];

    protected static $entityRightsRestrictionStates = [
        DBEntity::class => true
    ];

    /** @var DDDService */
    protected static $instance;


    public static function instance(): self
    {
        if (isset(self::$instance)) {
            return self::$instance;
        }
        /** @var self $instance */
        $instance = self::getServicePrivate(static::class);
        self::$instance = $instance;
        return $instance;
    }

    protected static function getContainerServiceClassNameForClassPrivate(string $className): ?string
    {
        if (isset(StaticRegistry::$frameworkToAppClassNames[$className])) {
            return StaticRegistry::$frameworkToAppClassNames[$className];
        }
        $path = self::getCacheDirPrivate(false) . '/service_class_map.php';
        if (!StaticRegistry::$containerServiceClassMap) {
            StaticRegistry::$containerServiceClassMap = include_once self::getCacheDirPrivate(
                    false
                ) . '/service_class_map.php';
        }
        if (isset(StaticRegistry::$containerServiceClassMap[$className]) && StaticRegistry::$containerServiceClassMap[$className] != $className) {
            StaticRegistry::$frameworkToAppClassNames[$className] = StaticRegistry::$containerServiceClassMap[$className];
            return StaticRegistry::$containerServiceClassMap[$className];
        }
        if (str_starts_with($className, self::FRAMEWORK_ROOT_NAMESPACE)) {
            $appClassName = self::APP_ROOT_NAMESPACE . substr($className, strlen(self::FRAMEWORK_ROOT_NAMESPACE));
            if (class_exists($appClassName)) {
                StaticRegistry::$frameworkToAppClassNames[$className] = StaticRegistry::$containerServiceClassMap[$appClassName];
                return $appClassName;
            }
        }
        StaticRegistry::$frameworkToAppClassNames[$className] = $className;
        return $className;
    }

    /**
     * Returns class name for given service name, this is particulary usefull as we sometimes dont want to create an instance for
     * each service in order to know its particular class name (which is eventually overwritten in the services definitions)
     * @param string $className
     * @return string|null
     */
    public function getContainerServiceClassNameForClass(string $className): ?string
    {
        return self::getContainerServiceClassNameForClassPrivate($className);
    }

    /**
     * @return RequestService|null
     */
    public function getRequestService(): ?RequestService
    {
        /** @var RequestService $requestService */
        $requestService = DDDBundle::getContainer()->get(RequestService::class);
        return $requestService;
    }

    /**
     * @return void Creates snapshot of current application related caches state
     */
    public function createCachesSnapshot(): void
    {
        self::$cacheStates = [
            'doctrineRegistry' => !DoctrineEntityRegistry::$clearCache,
            'virtualRegistry' => !VirtualEntityRegistry::$clearCache,
            'app' => !DDDService::$noCache,
            'classFinder' => !ClassFinder::$clearCache
        ];
        self::$cachesSnapshotSet = true;
    }

    /**
     * @return void Restores snapshot of application related caches state
     */
    public function restoreCachesSnapshot(): void
    {
        if (!self::$cachesSnapshotSet) {
            return;
        }
        DoctrineEntityRegistry::$clearCache = !self::$cacheStates['doctrineRegistry'];
        VirtualEntityRegistry::$clearCache = !self::$cacheStates['virtualRegistry'];
        DDDService::$noCache = !self::$cacheStates['app'];
        ClassFinder::$clearCache = !self::$cacheStates['classFinder'];
        self::$cachesSnapshotSet = false;
    }

    /**
     * @return void Deactivates all application related caches
     */
    public function deactivateCaches(): void
    {
        self::createCachesSnapshot();
        DoctrineEntityRegistry::$clearCache = true;
        VirtualEntityRegistry::$clearCache = true;
        ClassFinder::$clearCache = true;
        DDDService::$noCache = true;
    }

    /**
     * @return void Activates all application related caches
     */
    public function activateCaches(): void
    {
        self::createCachesSnapshot();
        DoctrineEntityRegistry::$clearCache = false;
        VirtualEntityRegistry::$clearCache = false;
        DDDService::$noCache = false;
        ClassFinder::$clearCache = false;
    }

    protected static function getServicePrivate(string $serviceName): mixed
    {
        $className = self::getContainerServiceClassNameForClassPrivate($serviceName);
        return DDDBundle::getContainer()->get($className);
    }

    /**
     * Returns any service from container
     * @param string $serviceName
     * @return mixed
     */
    public function getService(string $serviceName): mixed
    {
        return self::getServicePrivate($serviceName);
    }

    /**
     * @return DDDKernel Returns current Kernel
     */
    public function getKernel(): DDDKernel
    {
        /** @var DDDKernel $kernel */
        $kernel = self::getService('kernel');
        return $kernel;
    }

    /**
     * Returns the current app environment
     * @return string
     */
    public function getEnvironment(): string
    {
        return self::getService('kernel')->getEnvironment();
    }

    /**
     * Returns the kernel prefix of the AppKernel, e.g. api/admin, api/client etc.
     * @return string|null
     */
    public function getKernelPrefix(): ?string
    {
        /** @var DDDKernel $kernel */
        $kernel = self::getService('kernel');
        if (method_exists($kernel, 'getKernelPrefix')) {
            return $kernel->getKernelPrefix();
        }
        return null;
    }

    /**
     * Return the path of the console of the current kernel
     * @param bool $returnRelativePath
     * @return string
     * @throws Exception
     */
    public function getConsoleDir(bool $returnRelativePath = true): string
    {
        $kernelPrefix = self::getKernelPrefix();
        $consolePath = $returnRelativePath ? '' : self::getRootDir();
        $consolePath .= '/bin/console';
        if ($kernelPrefix) {
            $consolePath .= '_' . str_replace('/', '_', $kernelPrefix);
        }
        return $consolePath;
    }

    protected static function getCacheDirPrivate(bool $returnRelativePath = true): string
    {
        $path = DDDBundle::getContainer()->getParameter('kernel.cache_dir');
        if (!$returnRelativePath) {
            return $path;
        } else {
            return str_replace(self::getRootDirPrivate(), '', $path);
        }
    }

    /**
     * Returns file cache path of symfony
     * @param bool $returnRelativePath
     * @return string
     */
    public function getCacheDir(bool $returnRelativePath = true): string
    {
        return self::getCacheDirPrivate($returnRelativePath);
    }

    public function createConsoleApplicationForCurrentKernel(): Application
    {
        $kernel = self::getKernel();
        return new Application($kernel);
    }

    public static function getRootDirPrivate(): string
    {
        return DDDBundle::getContainer()->getParameter('kernel.project_dir');
    }

    /**
     * Returns the root directory of the project, e.g. /var/www/dev-workspaces/mgn/app
     * @return string
     */
    public function getRootDir(): string
    {
        return self::getRootDirPrivate();
    }

    /**
     * @return string Returns the root dir of the framework
     */
    public function getFrameworkRootDir(): string
    {
        if (isset(self::$frameworkDir)) {
            return self::$frameworkDir;
        } else {
            $appServiceReflectionClass = new ReflectionClass(self::class);
            $frameworkRoot = $appServiceReflectionClass->getFileName();

            // Climb up two levels from the AppService.php file to reach the ddd_src directory
            $rootPath = dirname(dirname(dirname($frameworkRoot)));

            return $rootPath;
        }
    }

    public function getLogger(): LoggerInterface
    {
        /** @var LoggerInterface $logger */
        $logger = self::getService('logger');
        return $logger;
    }

    public function getTemplateRenderer(): Environment
    {
        /** @var Environment $templatesEnvironment */
        $templatesEnvironment = self::getService('templates');
        return $templatesEnvironment;
    }

    /**
     * @return float Returns the percentage of memory used in percent of available memory
     */
    public function getMemoryLimitInBytes(): int
    {
        static $memoryLimit = null;
        if ($memoryLimit === null) {
            $memoryLimit = ini_get('memory_limit');
            if (preg_match('/^(\d+)(.)$/', $memoryLimit, $matches)) {
                if ($matches[2] == 'M') {
                    $memoryLimit = $matches[1] * 1024 * 1024;
                } elseif ($matches[2] == 'K') {
                    $memoryLimit = $matches[1] * 1024;         // KB to bytes
                } elseif ($matches[2] == 'G') {
                    $memoryLimit = $matches[1] * 1024 * 1024 * 1024;  // GB to bytes
                }
            }
        }
        return $memoryLimit;
    }

    /**
     * @return bool Returns true if memory usage is high (above 50 %)
     */
    public function isMemoryUsageHigh(): bool
    {
        return memory_get_usage() / self::getMemoryLimitInBytes() > 0.5;
    }

    /**
     * @return void Creates snapshot of current Entity rights restriction states
     */
    public function createEntityRightsRestrictionsStateSnapshot(): void
    {
        self::$entityRightsRestrictionStates = [
            DBEntity::class => DBEntity::getApplyRightsRestrictions()
        ];
        self::$entityRightsRestrictionSnapshotSet = true;
    }

    /**
     * @return void Restores snapshot of current Entity rights restriction states
     */
    public function restoreEntityRightsRestrictionsStateSnapshot(): void
    {
        if (!self::$entityRightsRestrictionSnapshotSet) {
            return;
        }
        DBEntity::setApplyRightsRestrictions(self::$entityRightsRestrictionStates[DBEntity::class]);
        self::$entityRightsRestrictionSnapshotSet = false;
    }

    /**
     * @return void Deactivates all application related caches
     */
    public function deactivateEntityRightsRestrictions(): void
    {
        self::createEntityRightsRestrictionsStateSnapshot();
        DBEntity::setApplyRightsRestrictions(false);
    }

    /**
     * Returns the configured default Admin Account for CLI operations since they are not run with a Account logged in but often require an admin Account
     * @return Account
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getDefaultAccountForCliOperations(): ?Account
    {
        $this->deactivateEntityRightsRestrictions();
        $account = Account::byId(Config::getEnv('CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS'));
        $this->restoreEntityRightsRestrictionsStateSnapshot();
        return $account;
    }
}