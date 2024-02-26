<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\CacheScopes\Invalidations;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations\DBCacheScopeInvalidations;
use DDD\Domain\Common\Services\CacheScopeInvalidationsService;

/**
 * @property CacheScopeInvalidation[] $elements;
 * @method CacheScopeInvalidation getByUniqueKey(string $uniqueKey)
 * @method CacheScopeInvalidation first()
 * @method CacheScopeInvalidation[] getElements()
 * @method static CacheScopeInvalidationsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBCacheScopeInvalidations::class)]
#[QueryOptions(top: 10)]
class CacheScopeInvalidations extends EntitySet
{
    public const SERVICE_NAME = CacheScopeInvalidationsService::class;

    /**
     * Returns CacheScopeInvalidation by parameters
     * @param string $cacheScope
     * @param int|null $accountId
     * @param int|null $projectId
     * @param int|null $locationId
     * @return CacheScopeInvalidation|null
     */
    public function getCacheScopeInvalidationbyParameters(
        array $cacheScopes,
        ?int $accountId = null,
        ?int $projectId = null,
        ?int $locationId = null,
    ): ?CacheScopeInvalidation {
        foreach ($cacheScopes as $cacheScope) {
            $key = $cacheScope . '_' . ($accountId ?? '') . '_' . ($projectId ?? '') . '_' . ($locationId ?? '');
            $cacheScopeInvalidation = $this->getByUniqueKey(CacheScopeInvalidation::uniqueKeyStatic($key));
            if ($cacheScopeInvalidation) {
                return $cacheScopeInvalidation;
            }
        }
        return null;
    }

}