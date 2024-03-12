<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use DDD\Infrastructure\Cache\Predis\Client;
use DDD\Infrastructure\Libs\Config;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

abstract class Cache
{
    private const DEEFAULT_TTL = 3600;
    public const CACHE_TTL_ONE_DAY = 86400;
    public const CACHE_TTL_ONE_HOUR = 3600;
    public const CACHE_TTL_TEN_MINUTES = 600;
    public const CACHE_TTL_ONE_WEEK = 604800;
    public const CACHE_TTL_ONE_MONTH = 2292000;
    public const CACHE_GROUP_APC = 'apc';
    public const CACHE_GROUP_PHPFILES = 'phpfiles';
    public const CACHE_GROUP_REDIS = 'redis';
    public const CACHE_GROUP_REDIS_SENTINEL = 'redisSentinel';

    // Default Groups
    protected const CACHE_TYPE_APC = 'apc';
    protected const CACHE_TYPE_REDIS = 'redis';
    protected const CACHE_TYPE_PHPFILES = 'phpfiles';
    protected const CACHE_TYPE_REDIS_SENTINEL = 'redisSentinel';

    protected const CACHE_GROUP_TO_ENV_ALLOCATION = [
        self::CACHE_GROUP_APC => 'APC',
        self::CACHE_GROUP_PHPFILES => 'PHPFILES',
        self::CACHE_GROUP_REDIS => 'REDIS',
        self::CACHE_GROUP_REDIS_SENTINEL => 'REDIS_SENTINEL',
    ];

    // Types
    /** @var Apc[] */
    protected static ?array $instances = null;
    public int $ttl = self::DEEFAULT_TTL;
    protected ?array $config = null;

    public function __construct(array $config, protected ?MarshallerInterface $marshaller = null)
    {
        $this->config = $config;
        $this->ttl = (int) $config['defaultTTL'] ?? self::DEEFAULT_TTL;
    }

    public static function instance(string $cacheGroup = null): static
    {
        if (!$cacheGroup) {
            $cacheGroup = self::CACHE_GROUP_APC;
        }
        $cacheGroupConfig = self::getCacheConfig($cacheGroup);
        if (empty($cacheGroupConfig)) {
            $cacheGroupConfig = self::getCacheConfig(self::CACHE_GROUP_APC);
        }

        $cacheType = $cacheGroupConfig['cacheGroup'] ?? self::CACHE_TYPE_APC;

        return match ($cacheType) {
            self::CACHE_TYPE_REDIS => Redis::getInstance($cacheGroup, $cacheGroupConfig),
            self::CACHE_TYPE_PHPFILES => PhpFiles::getInstance($cacheGroup, $cacheGroupConfig),
            self::CACHE_TYPE_REDIS_SENTINEL => RedisSentinel::getInstance($cacheGroup, $cacheGroupConfig),
            default => Apc::getInstance($cacheGroup, $cacheGroupConfig),
        };
    }

    public static function getCacheConfig(string $cacheGroup):array {
        $cacheConfig = [];
        if (isset(self::CACHE_GROUP_TO_ENV_ALLOCATION[$cacheGroup]))
            $cacheGroupIndex = self::CACHE_GROUP_TO_ENV_ALLOCATION[$cacheGroup];
        else
            $cacheGroupIndex = strtoupper($cacheGroup);
        $allocation = [
            'NAMESPACE' => 'namespace',
            'TTL' => 'defaultTTL',
            'DIRECTORY' => 'directory',
            'PASSWORD' => 'password',
            'HOST' => 'host',
            'PORT' => 'port',
            'DATABASE' => 'database',
            'SENTINELS' => 'sentinels',
            'CACHE_GROUP' => 'cacheGroup',
        ];
        foreach ($allocation as $envIndex => $variableIndex){
            if ($envVariable = Config::getEnv("CACHE_{$cacheGroupIndex}_{$envIndex}")){
                if (is_string($envVariable)) {
                    $envVariable = str_replace('%APP_PREFIX%', APP_PREFIX, $envVariable);
                    $envVariable = str_replace('%APP_ROOT_DIR%', APP_ROOT_DIR, $envVariable);
                }
                if ($envIndex == 'SENTINELS')
                    $envVariable = explode(',',$envVariable);
                $cacheConfig[$variableIndex] = $envVariable;
            }
        }
        echo json_encode($cacheConfig);
        return $cacheConfig;
    }

    /**
     * obtains values from $keys, if multiple keys are provided, it returns the results as associative array grouped by the keys,
     * otherwise it returns a single result.
     * if no result is found, returns null
     * @param string $key
     * @return mixed
     */
    public function get(string ...$keys): mixed
    {
        //$this->delete($keys[0]);
        $keys = array_map(function ($a) {
            return self::convertKeyToValidCacheKey($a);
        }, $keys);
        $cache = $this->getCacheAdapter();
        if (count($keys) > 1) {
            $items = $cache->getItems($keys);
            if (!$items) {
                return false;
            }
            $returnItems = [];

            /** @var CacheItem $item */
            foreach ($items as $item) {
                if ($item->isHit()) {
                    $returnItems[self::convertKeyToValidCacheKey($item->getKey(), true)] = $item->get();
                }
            }
            return $returnItems;
        } else {
            $item = $cache->getItem($keys[0]);
            if (!$item->isHit()) {
                return false;
            }
            return $item->get();
        }
    }

    public static function convertKeyToValidCacheKey(string $key, $inverse = false): string
    {
        if (!$inverse) {
            $replace = str_replace('\\', '_.#.#._', $key);
            $replace = str_replace('/', '**.-**', $replace);
            return $replace;
        } else {
            $replace = str_replace('_.#.#._', '\\', $key);
            $replace = str_replace('**.-**', '/', $replace);
            return $replace;
        }
    }

    /**
     * returns instance of particular cache adapter
     * @return Redis
     */
    abstract public function getCacheAdapter(): Client|ApcuAdapter|RedisAdapter|PhpFilesAdapter;

    /**
     * creates instance of specific cache class, and stores it static by $cacheGroup as key.
     * if already present, returns the already created one.
     * @param string $cacheGroup
     * @param array $config
     * @return static
     */
    protected static function getInstance(string $cacheGroup, array &$config): static
    {
        if (!isset(static::$instances)) {
            static::$instances = [];
        }
        if (!isset(static::$instances[$cacheGroup])) {
            static::$instances[$cacheGroup] = new static($config);
        }
        return static::$instances[$cacheGroup];
    }

    public static function getDefaultTtl(string $cacheType): int
    {
        $cacheConfig = self::getCacheConfig($cacheType);
        $ttl = Config::getEnv('CACHE_DEFAULT_TTL') ?? 3600;

        if (!$ttl) {
            $ttl = Config::getEnv('CACHE_DEFAULT_TTL') ?? self::DEEFAULT_TTL;
        }
        if (!$ttl) {
            $ttl = self::DEEFAULT_TTL;
        }
        return $ttl;
    }

    /**
     * Saves a value to cache, if $deferred is true, value is not commited until commit is explicitely called
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl
     * @param bool $deferred
     * @return void
     * @throws InvalidArgumentException
     */
    public function set(string $key, mixed $value, int $ttl = null, bool $deferred = false): void
    {
        $ttl = $ttl ? $ttl : $this->ttl;
        $cache = $this->getCacheAdapter();
        $item = $cache->getItem(self::convertKeyToValidCacheKey($key));
        //
        $item->set($value);
        $item->expiresAfter($ttl);
        if (!$deferred) {
            $cache->save($item);
        } else {
            $cache->saveDeferred($item);
        }
    }

    /**
     * Commits values set with $deferred true all in once
     * @return void
     */
    public function commit()
    {
        $cache = $this->getCacheAdapter();
        $cache->commit();
    }

    /**
     * clear the cache
     * @return void
     */
    public function clear(): void
    {
        $cache = $this->getCacheAdapter();
        $cache->clear();
    }

    /**
     * deletes cache entry for given key
     * @param string $key
     * @return void
     */
    public function delete(string $key): void
    {
        $cache = $this->getCacheAdapter();
        $cache->deleteItem(self::convertKeyToValidCacheKey($key));
    }
}