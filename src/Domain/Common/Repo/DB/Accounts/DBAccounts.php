<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Accounts;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Accounts;

/**
 * @method Accounts find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAccounts extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBAccount::class;
    public const BASE_ENTITY_SET_CLASS = Accounts::class;
}