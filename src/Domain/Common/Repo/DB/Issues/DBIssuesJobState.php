<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Issues;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Issues\IssuesJobState;
use DDD\Infrastructure\Services\AuthService;

/**
 * Issues registry: the job's state. Readable by admins only — the registry job and the CLI run with rights restrictions off.
 */
class DBIssuesJobState extends DBEntity
{
    public const string BASE_ENTITY_CLASS = IssuesJobState::class;
    public const string BASE_ORM_MODEL = DBIssuesJobStateModel::class;

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
