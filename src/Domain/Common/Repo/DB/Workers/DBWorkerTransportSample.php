<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Workers;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Workers\WorkerTransportSample;
use DDD\Infrastructure\Services\AuthService;

/**
 * Worker monitoring: readable by admins only — the monitoring itself and the console write and read with rights
 * restrictions off.
 *
 * @property DBWorkerTransportSampleModel $ormInstance
 */
class DBWorkerTransportSample extends DBEntity
{
    public const string BASE_ENTITY_CLASS = WorkerTransportSample::class;
    public const string BASE_ORM_MODEL = DBWorkerTransportSampleModel::class;

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
