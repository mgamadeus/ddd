<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Accounts\LoginTokens;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\LoginTokens\LoginTokens;

/**
 * @method LoginTokens find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBLoginTokens extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBLoginToken::class;
    public const string BASE_ENTITY_SET_CLASS = LoginTokens::class;
}