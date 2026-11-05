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
    public const string FRAMEWORK_ROOT_NAMESPACE = 'DDD';

    public const string APP_ROOT_NAMESPACE = 'App';

    public const string ENV_DEV = 'dev';

    public const string ENV_PROD = 'prod';

    /** @var bool If true, the service itself will throw errors */
    public bool $throwErrors = false;

    /** @var bool Controls caching behaviour, app components can access this variable in order to decide if they apply caching or not */
    public static bool $noCache = false;

    protected static $cachesSnapshotSet = false;

    /**
     * @var array<int, bool[]> Cache states pushed by createCachesSnapshot() (and so by deactivateCaches() /
     * activateCaches()) and popped by restoreCachesSnapshot(), so nested pairs each restore their own previous state.
     * $cachesSnapshotSet and $cacheStates mirror the top of this stack for subclasses that still read them.
     */
    protected static array $cachesStateStack = [];

    protected static $entityRightsRestrictionSnapshotSet = false;

    /**
     * @var bool[] Entity rights restriction states pushed by createEntityRightsRestrictionsStateSnapshot() /
     * deactivateEntityRightsRestrictions() and popped by restoreEntityRightsRestrictionsStateSnapshot(), so nested
     * pairs each restore their own previous state. $entityRightsRestrictionSnapshotSet and
     * $entityRightsRestrictionStates mirror the top of this stack for subclasses that still read them.
     */
    protected static array $entityRightsRestrictionsStateStack = [];

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
            try {
                if (class_exists($appClassName)) {
                    StaticRegistry::$frameworkToAppClassNames[$className] = StaticRegistry::$containerServiceClassMap[$appClassName];
                    return $appClassName;
                }
            }
            catch (Exception){

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
     * Pushes the current cache states. Every push is matched by exactly one restoreCachesSnapshot(), in a `finally`;
     * pairs may be nested.
     *
     * @return void
     */
    public function createCachesSnapshot(): void
    {
        $currentCacheStates = $this->getCurrentCacheStates();
        self::$cachesStateStack[] = $currentCacheStates;
        self::$cacheStates = $currentCacheStates;
        self::$cachesSnapshotSet = true;
    }

    /**
     * Pops the newest snapshot and applies it, so a nested pair restores the state of the enclosing window, not the
     * state before the outermost one. A restore without a snapshot changes nothing.
     *
     * @return void
     */
    public function restoreCachesSnapshot(): void
    {
        if (!self::$cachesStateStack) {
            return;
        }
        $this->applyCacheStates(array_pop(self::$cachesStateStack));
        self::$cachesSnapshotSet = self::$cachesStateStack !== [];
        if (self::$cachesSnapshotSet) {
            self::$cacheStates = self::$cachesStateStack[array_key_last(self::$cachesStateStack)];
        }
    }

    /**
     * Whether each cache is ON right now. The extension point for an application that adds a cache switch: it
     * overrides this and {@see applyCacheStates()} (parent result plus its own key) instead of the public methods.
     *
     * @return bool[] Cache state by cache name, true = ON
     */
    protected function getCurrentCacheStates(): array
    {
        return [
            'doctrineRegistry' => !DoctrineEntityRegistry::$clearCache,
            'virtualRegistry' => !VirtualEntityRegistry::$clearCache,
            'app' => !DDDService::$noCache,
            'classFinder' => !ClassFinder::$clearCache
        ];
    }

    /**
     * Switches every cache to the given state; a cache missing from the states is switched ON.
     *
     * @param bool[] $cacheStates Cache state by cache name as returned by getCurrentCacheStates()
     * @return void
     */
    protected function applyCacheStates(array $cacheStates): void
    {
        DoctrineEntityRegistry::$clearCache = !($cacheStates['doctrineRegistry'] ?? true);
        VirtualEntityRegistry::$clearCache = !($cacheStates['virtualRegistry'] ?? true);
        DDDService::$noCache = !($cacheStates['app'] ?? true);
        ClassFinder::$clearCache = !($cacheStates['classFinder'] ?? true);
    }

    /**
     * Deactivates all application related caches. Pushes a snapshot first: pair it with restoreCachesSnapshot() in a
     * `finally`; pairs may be nested.
     *
     * @return void
     */
    public function deactivateCaches(): void
    {
        $this->createCachesSnapshot();
        $this->applyCacheStates($this->getCacheStatesAllSwitchedTo(false));
    }

    /**
     * Every cache this service knows — the framework's and those an application adds through
     * {@see getCurrentCacheStates()} — mapped to one state, so deactivateCaches() / activateCaches() switch the
     * application's caches too without being overridden.
     *
     * @param bool $cachesOn true = every cache ON
     * @return bool[] Cache state by cache name
     */
    protected function getCacheStatesAllSwitchedTo(bool $cachesOn): array
    {
        return array_fill_keys(array_keys($this->getCurrentCacheStates()), $cachesOn);
    }

    /**
     * A new worker job starts with every cache ON and no cache-state snapshot — a job that died inside a
     * deactivateCaches/restoreCachesSnapshot pair would otherwise leave the next jobs of the process without caches.
     * Called by the handler base's job reset only.
     */
    public function resetCachesStateForNewJob(): void
    {
        // every cache ON, including the switches an application adds through applyCacheStates()
        $this->applyCacheStates([]);
        self::$cachesStateStack = [];
        self::$cachesSnapshotSet = false;
    }

    /**
     * Activates all application related caches. Pushes a snapshot first like deactivateCaches(): pair it with its own
     * restoreCachesSnapshot() in a `finally`; pairs may be nested.
     *
     * @return void
     */
    public function activateCaches(): void
    {
        $this->createCachesSnapshot();
        $this->applyCacheStates($this->getCacheStatesAllSwitchedTo(true));
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
     * Returns the root directory of the project, e.g. /var/www/dev-workspaces/<workspace>/app
     * @return string
     */
    public function getRootDir(): string
    {
        return self::getRootDirPrivate();
    }

    /** @var string The path segment that marks a WORKSPACE checkout; the segment after it is the workspace's name. */
    public const string WORKSPACES_PATH_SEGMENT = '/dev-workspaces/';

    /**
     * The name of the workspace this process runs in — `/var/www/dev-workspaces/prj6/app` → `prj6` — or null outside
     * a workspace (production, a developer's machine). Several checkouts of one app share a machine, a database and a
     * message broker; everything that must be told apart per checkout (the broker vhost, the cache namespace, the
     * origin stamp on a message) derives its name from THIS method, so no two of them can ever disagree.
     */
    public function getWorkspaceName(): ?string
    {
        return self::workspaceNameFromRootDir(self::getRootDirPrivate());
    }

    /** The pure derivation behind {@see getWorkspaceName()}. */
    public static function workspaceNameFromRootDir(string $rootDir, ?string $workspacesPathSegment = null): ?string
    {
        // The segment is a PARAMETER wherever one is configured ({@see
        // \DDD\Symfony\DependencyInjection\EnvVarProcessors\WorkspaceVhostEnvVarProcessor::PATH_SEGMENT_PARAMETER});
        // the constant below is only its default, so a deployment that lays its checkouts out differently changes
        // ONE value and every derivation follows.
        $workspacesPathSegment = $workspacesPathSegment !== null && $workspacesPathSegment !== ''
            ? $workspacesPathSegment
            : self::WORKSPACES_PATH_SEGMENT;
        if (preg_match('#' . preg_quote($workspacesPathSegment, '#') . '([^/]+)/#', rtrim($rootDir, '/') . '/', $matches) !== 1) {
            return null;
        }
        return $matches[1];
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
     * Pushes the current Entity rights restriction state onto the snapshot stack.
     * Every push must be matched by exactly one {@see self::restoreEntityRightsRestrictionsStateSnapshot()},
     * typically in a `finally` block; a push without a matching restore stays on the stack.
     * @return void
     */
    public function createEntityRightsRestrictionsStateSnapshot(): void
    {
        $currentState = DBEntity::getApplyRightsRestrictions();
        self::$entityRightsRestrictionsStateStack[] = $currentState;
        self::$entityRightsRestrictionStates = [
            DBEntity::class => $currentState
        ];
        self::$entityRightsRestrictionSnapshotSet = true;
    }

    /**
     * Pops the most recent snapshot and restores that Entity rights restriction state. No-op on an empty stack, so it
     * is safe in a `finally` block. A restore only undoes its own matching create/deactivate: restoring twice for one
     * deactivate pops the ENCLOSING snapshot and re-activates restrictions before the outer scope has finished.
     * @return void
     */
    public function restoreEntityRightsRestrictionsStateSnapshot(): void
    {
        if (!self::$entityRightsRestrictionsStateStack) {
            return;
        }
        DBEntity::setApplyRightsRestrictions(array_pop(self::$entityRightsRestrictionsStateStack));
        self::$entityRightsRestrictionSnapshotSet = self::$entityRightsRestrictionsStateStack !== [];
        if (self::$entityRightsRestrictionSnapshotSet) {
            self::$entityRightsRestrictionStates = [
                DBEntity::class => self::$entityRightsRestrictionsStateStack[array_key_last(self::$entityRightsRestrictionsStateStack)]
            ];
        }
    }

    /**
     * A new worker job starts with rights restrictions ON and an EMPTY snapshot stack — a job that died inside a
     * deactivate/restore pair would otherwise leave every later job of the process without rights, and its stale
     * frames would be popped by restores that never pushed them. Called by the handler base's job reset only.
     */
    public function resetEntityRightsRestrictionsStateForNewJob(): void
    {
        self::$entityRightsRestrictionsStateStack = [];
        self::$entityRightsRestrictionSnapshotSet = false;
        self::$entityRightsRestrictionStates = [DBEntity::class => true];
        DBEntity::setApplyRightsRestrictions(true);
    }

    /**
     * Snapshots the current Entity rights restriction state and deactivates restrictions. Pair every call with
     * {@see self::restoreEntityRightsRestrictionsStateSnapshot()} in a `finally`; pairs may be nested.
     * @return void
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