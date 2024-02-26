<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations;

use DDD\Domain\Common\Entities\CacheScopes\Invalidations\CacheScopeInvalidations;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method CacheScopeInvalidations find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBCacheScopeInvalidations extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBCacheScopeInvalidation::class;
    public const BASE_ENTITY_SET_CLASS = CacheScopeInvalidations::class;
}