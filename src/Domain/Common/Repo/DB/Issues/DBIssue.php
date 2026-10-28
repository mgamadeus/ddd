<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Issues;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Issues\Issue;
use DDD\Infrastructure\Services\AuthService;

/**
 * Issues registry: issues of a group. Readable by admins only — the registry job and the CLI run with rights restrictions off.
 */
class DBIssue extends DBEntity
{
    public const string BASE_ENTITY_CLASS = Issue::class;
    public const string BASE_ORM_MODEL = DBIssueModel::class;

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
