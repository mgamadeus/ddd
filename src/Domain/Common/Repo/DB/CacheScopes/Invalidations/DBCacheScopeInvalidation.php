<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations;

use DateInterval;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Entities\CacheScopes\Invalidations\CacheScopeInvalidation;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Exception;
use ReflectionException;

/**
 * @method CacheScopeInvalidation find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBCacheScopeInvalidationModel $ormInstance
 * @method CacheScopeInvalidation update(DefaultObject &$entity, int $depth = self::UPDATE_DEFAULT_RECURSIVE_DEPTH)
 */
class DBCacheScopeInvalidation extends DBEntity
{
    public const string BASE_ENTITY_CLASS = CacheScopeInvalidation::class;
    public const string BASE_ORM_MODEL = DBCacheScopeInvalidationModel::class;

    /**
     * Deletes expired Tokens
     * @return void
     * @throws Exception
     * @throws ReflectionException
     */
    public function deleteExpiredCacheScopeInvalidations(): void
    {
        $createdDateThreshold = (new DateTime())->sub(new DateInterval('P1D'));
        // DQL, not raw SQL: the identifiers are quoted per engine (PostgreSQL folds unquoted mixed case to lower case)
        EntityManagerFactory::getInstance()->createQueryBuilder()
            ->delete(static::BASE_ORM_MODEL, 'cacheScopeInvalidation')
            ->where(
                '(cacheScopeInvalidation.numberOfTimesToInvalidateCache IS NOT NULL AND cacheScopeInvalidation.numberOfTimesToInvalidateCache <= 0)'
                . ' OR (cacheScopeInvalidation.invalidateUntil IS NOT NULL AND cacheScopeInvalidation.invalidateUntil < :currentDate)'
                . ' OR (cacheScopeInvalidation.created IS NOT NULL AND cacheScopeInvalidation.created < :createdDayThreshold)'
            )
            ->setParameter('currentDate', new DateTime(), Types::DATETIME_MUTABLE)
            ->setParameter('createdDayThreshold', $createdDateThreshold, Types::DATETIME_MUTABLE)
            ->getQuery()
            ->execute();
    }
}