<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Workers;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Infrastructure\Services\AuthService;

/**
 * Worker monitoring: readable by admins only — the monitoring itself and the console write and read with rights
 * restrictions off.
 *
 * @property DBWorkerProcessModel $ormInstance
 */
class DBWorkerProcess extends DBEntity
{
    public const string BASE_ENTITY_CLASS = WorkerProcess::class;
    public const string BASE_ORM_MODEL = DBWorkerProcessModel::class;

    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        if (!(AuthService::instance()->getAccount()?->roles?->isAdmin() ?? false)) {
            $queryBuilder->andWhere(static::getBaseModelAlias() . '.id is null');
            return true;
        }
        return false;
    }
}
