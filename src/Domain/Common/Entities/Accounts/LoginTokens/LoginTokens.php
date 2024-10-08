<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Accounts\LoginTokens;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Accounts\LoginTokens\DBLoginToken;
use DDD\Domain\Common\Repo\DB\Accounts\LoginTokens\DBLoginTokens;
use DDD\Domain\Common\Services\LoginTokensService;

/**
 * @property LoginToken[] $elements;
 * @method LoginToken getByUniqueKey(string $uniqueKey)
 * @method LoginToken first()
 * @method LoginToken[] getElements()
 * @method static DBLoginToken getRepoClassInstance(string $repoType = null)
 * @method static LoginTokensService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBLoginTokens::class)]
#[QueryOptions(top: 10)]
class LoginTokens extends EntitySet
{
    use QueryOptionsTrait;
}