<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Infrastructure\Cache\Cache;

/**
 * Encapsules definition for cache usage of Repositories;
 * By default static caching is applied and in addition the possibilty extits to apply an extended registry caching
 */
#[Attribute(Attribute::TARGET_CLASS)]
class EntityCache
{
    use BaseAttributeTrait;

    /** @var bool whether to use registry APC cache for this Repo Entity or not */
    public bool $useExtendedRegistryCache = true;

    /** @var bool registry APC cache ttl for this Repo OrmEntity */
    public int $ttl = 300;
    
    /** @var string Cache group to be used */
    public string $cacheGroup = Cache::CACHE_GROUP_APC;

    /** @var array CacheScopes are used in order to temporary invalidate caching by checking EntityCacheScopeInvalidations */
    public array $cacheScopes = [];

    /**
     * Encapsules definitions for cache usage of repositories.
     * CacheScopes use EntityCacheScopeInvalidations in order to temporary invalidate caching
     * @param bool $useExtendedRegistryCache
     * @param int $ttl
     * @param string $cacheGroup
     * @param array $cacheScopes
     */
    public function __construct(
        bool $useExtendedRegistryCache = false,
        int $ttl = 300,
        string $cacheGroup = Cache::CACHE_GROUP_APC,
        array $cacheScopes = []
    ) {
        $this->useExtendedRegistryCache = $useExtendedRegistryCache;
        $this->cacheGroup = $cacheGroup;
        $this->ttl = $ttl;
        $this->cacheScopes = $cacheScopes;
    }
}