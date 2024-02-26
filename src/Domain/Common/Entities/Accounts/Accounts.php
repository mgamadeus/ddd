<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Accounts;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccounts;
use DDD\Domain\Common\Services\AccountsService;

/**
 * @property Account[] $elements;
 * @method Account getByUniqueKey(string $uniqueKey)
 * @method Account first()
 * @method Account[] getElements()
 * @method static AccountsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAccounts::class)]
#[QueryOptions(top: 10)]
class Accounts extends EntitySet
{
    use QueryOptionsTrait;

    public const SERVICE_NAME = AccountsService::class;
}