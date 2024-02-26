<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Crons;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Infrastructure\Services\AuthService;

/**
 * @method Cron find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method Cron update(Entity &$entity, int $depth = 1)
 * @property DBCronModel $ormInstance
 */
class DBCron extends DBEntity
{
    public const BASE_ENTITY_CLASS = Cron::class;
    public const BASE_ORM_MODEL = DBCronModel::class;

    /**
     * Applies restrictions based on Auth::getAccount
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        $class = static::class;
        $cronAlias = static::getBaseModelAlias();

        /* only if rights restrictions are active, we limit the access */
        if (!self::$applyRightsRestrictions) {
            return false;
        }

        /* if no Auth Account is present we add an impossible condition in order to avoid loading any Cron */
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount) {
            $queryBuilder->andWhere("{$cronAlias}.id is null");
            return true;
        }

        return false;
    }

    /**
     * Applies update/delete restrictions based on Auth::getAccount
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool
     */
    public static function applyUpdateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        $class = static::class;
        $cronAlias = static::getBaseModelAlias();

        /* only if rights restrictions are active, we limit the access */
        if (!self::$applyRightsRestrictions) {
            return false;
        }

        /* if no Auth Account is present we add an impossible condition in order to avoid updating/deleting any Cron */
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount) {
            $queryBuilder->andWhere("{$cronAlias}.id is null");
            return true;
        }

        /* if the Auth Account is not an admin we add an impossible condition in order to avoid updating or deleting any Cron */
        if (!$authAccount->roles->isAdmin()) {
            $queryBuilder->andWhere("{$cronAlias}.id is null");
            return true;
        }

        return false;
    }
}