<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Crons;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Infrastructure\Services\AuthService;

/**
 * @method CronExecution find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method CronExecution update(Entity &$entity, int $depth = 1)
 * @property DBCronExecutionModel $ormInstance
 */
class DBCronExecution extends DBEntity
{
    public const BASE_ENTITY_CLASS = CronExecution::class;
    public const BASE_ORM_MODEL = DBCronExecutionModel::class;

    /**
     * Applies restrictions based on Auth::getAccount
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        $class = static::class;
        $cronExecutionAlias = static::getBaseModelAlias();

        /* only if rights restrictions are active, we limit the access */
        if (!self::$applyRightsRestrictions) {
            return false;
        }

        /* if no Auth Account is present we add an impossible condition in order to avoid loading any Cron Execution */
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount) {
            $queryBuilder->andWhere("{$cronExecutionAlias}.id is null");
            return true;
        }

        return false;
    }
}