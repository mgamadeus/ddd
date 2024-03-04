<?php

declare(strict_types=1);


namespace DDD\Domain\Base\Repo\DB\Doctrine;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Traits\SingletonTrait;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * A global Registry for Entities instances which serves as a static Cache for them in order to assure that we are working with the identical instances
 * whenever uniqueKey is equal
 * @method static DoctrineEntityRegistry getInstance
 */
class DoctrineEntityRegistry
{
    use SingletonTrait;

    /** @var bool if true, cache will be cleared on execution */
    public static bool $clearCache = false;

    /** @var string the default prefix used for cache keys */
    static string $defaultCachePrefix = 'EntityRegistry_';

    /** @var  Entity|EntitySet|null */
    protected static array $entityRegistry = [];

    /** @var string[] Cache Groups that contain deferred cache elements */
    protected static array $deferredCacheGroups = [];

    /**
     * Adds entity to registry by using it's id or a queryBuilder hash as type as key
     * If extended registry cache is defined to be used, stores the entity as well in extended entity registry cache
     * @param DefaultObject|null $entity
     * @param string $repoClass
     * @param string|int|DoctrineQueryBuilder $idOrQuery
     * @param bool $deferredCaching
     * @return void
     * @throws InvalidArgumentException
     */
    public function add(
        ?DefaultObject &$entity,
        string $repoClass,
        string|int|DoctrineQueryBuilder $idOrQuery,
        bool $deferredCaching = false
    ):void {
        // if memory usage is too high, we do not store new objects
        if (DDDService::instance()->isMemoryUsageHigh()){
            self::$entityRegistry = [];
            gc_collect_cycles();
            return;
        }
        $entityCacheAttribute = null;
        $registryIndex = $repoClass . '_' . ($idOrQuery instanceof DoctrineQueryBuilder ? $idOrQuery->getQueryHash(
            ) : $idOrQuery);

        /** @var EntityCache $entityCacheAttribute */
        $reflectionClass = ReflectionClass::instance($repoClass);
        $entityCacheAttribute = $reflectionClass->getAttributeInstance(EntityCache::class);

        if ($entityCacheAttribute && $entityCacheAttribute->useExtendedRegistryCache && $entityCacheAttribute->ttl) {
            $cache = Cache::instance($entityCacheAttribute->cacheGroup);
            $cacheValue = $entity?->toJSON(true);
            $cache->set(
                self::getCacheKey($registryIndex),
                $cacheValue,
                $entityCacheAttribute->ttl,
                $deferredCaching
            );
            if ($deferredCaching) {
                self::$deferredCacheGroups[$entityCacheAttribute->cacheGroup] = true;
            }
        }
        // by cloning we want to avoid issues when reference is returned and the reference is manipulated somewhere else
        self::$entityRegistry[$registryIndex] = $entity ? clone $entity: $entity;
    }

    /**
     * Get an Entity from Registry by by query hash instead of uniqueKey, especially usefull when we don't have an id yet
     * If extended registry cache is defined to be used, tries to find it in the cache.
     * If no result is found, returns false
     * @param string $repoClass
     * @param string|int|DoctrineQueryBuilder $idOrQuery
     * @return Entity|EntitySet|false|null
     * @throws InternalErrorException
     * @throws BadRequestException
     * @throws ReflectionException
     */
    public function get(string $repoClass, string|int|DoctrineQueryBuilder $idOrQuery): DefaultObject|null|false
    {
        if (self::$clearCache) {
            return null;
        }
        $registryIndex = $repoClass . '_' . ($idOrQuery instanceof DoctrineQueryBuilder ? $idOrQuery->getQueryHash(
            ) : $idOrQuery);
        $return = isset(self::$entityRegistry[$registryIndex]) ? self::$entityRegistry[$registryIndex] : false;
        if ($return){
            // by cloning we want to avoid issues when reference is returned and the reference is manipulated somewhere else
            return clone $return;
        }

        if ($return === false) {
            /** @var EntityCache $entityCacheAttribute */
            $reflectionClass = ReflectionClass::instance($repoClass);
            $entityCacheAttribute = $reflectionClass->getAttributeInstance(EntityCache::class);

            if ($entityCacheAttribute && $entityCacheAttribute->useExtendedRegistryCache && $entityCacheAttribute->ttl) {
                $cache = Cache::instance($entityCacheAttribute->cacheGroup);
                $return = $cache->get(self::getCacheKey($registryIndex));
                if ($return) {
                    $entityOrEntitySetClass = '';
                    if (defined($repoClass . '::BASE_ENTITY_CLASS')) {
                        $entityOrEntitySetClass = $repoClass::BASE_ENTITY_CLASS;
                    }
                    if (!$entityOrEntitySetClass && defined($repoClass . '::BASE_ENTITY_SET_CLASS')) {
                        $entityOrEntitySetClass = $repoClass::BASE_ENTITY_SET_CLASS;
                    }
                    if (!$entityOrEntitySetClass) {
                        throw new InternalErrorException(
                            "Repo Class {$repoClass} has no valid BASE_ENTITY_CLASS or BASE_ENTITY_SET_CLASS defined"
                        );
                    }
                    /** @var DefaultObject $entityInstance */
                    $entityInstance = new $entityOrEntitySetClass();
                    $return = json_decode($return);
                    $entityInstance->setPropertiesFromObject($return);
                    $return = $entityInstance;
                    // we store value retrieved from cache also in static registry
                    self::$entityRegistry[$registryIndex] = $return;
                }
            }
        }
        return $return;
    }

    /**
     * Commit all deferred cache groups
     * @return void
     */
    public static function commit()
    {
        foreach (self::$deferredCacheGroups as $deferredCacheGroup => $true) {
            $cache = Cache::instance($deferredCacheGroup);
            $cache->commit();
        }
        self::$deferredCacheGroups = [];
    }

    /**
     * Returns cache key combined with prefix and APP_ROOT_DIR in order to avoid workspaces influencing each other
     * @param string $cacheKey
     * @return string
     */
    public static function getCacheKey(string $cacheKey): string {
        return self::$defaultCachePrefix . '_'. md5(APP_ROOT_DIR . '_' . $cacheKey);
    }
}