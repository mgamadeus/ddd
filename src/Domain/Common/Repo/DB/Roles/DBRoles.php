<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Roles;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Roles\Roles;

/**
 * @method Roles find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBRoles extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBRole::class;
    public const BASE_ENTITY_SET_CLASS = Roles::class;
}