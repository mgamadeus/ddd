<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations;

use DateInterval;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Entities\CacheScopes\Invalidations\CacheScopeInvalidation;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Doctrine\DBAL\Exception;
use ReflectionException;

/**
 * @method CacheScopeInvalidation find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBCacheScopeInvalidationModel $ormInstance
 */
class DBCacheScopeInvalidation extends DBEntity
{
    public const BASE_ENTITY_CLASS = CacheScopeInvalidation::class;
    public const BASE_ORM_MODEL = DBCacheScopeInvalidationModel::class;

    /**
     * Deletes expired Tokens
     * @return void
     * @throws Exception
     * @throws ReflectionException
     */
    public function deleteExpiredCacheScopeInvalidations(): void
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $now = new DateTime();
        $createdDateThreshold = $now->sub(new DateInterval('P' . 1 . 'D'));
        $connection->executeStatement(
            'DELETE FROM ' . DBCacheScopeInvalidationModel::getTableName()
            . ' WHERE (numberOfTimesToInvalidateCache is not null and numberOfTimesToInvalidateCache <= 0) 
                  OR (invalidateUntil is not null and invalidateUntil < :currentDate) OR (created is not null and created < :createdDayThreshold)',
            ['currentDate' => (string)new DateTime(), 'createdDayThreshold' => $createdDateThreshold]
        );
    }
}