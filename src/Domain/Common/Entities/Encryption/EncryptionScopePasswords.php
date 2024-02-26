<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Encryption;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Encryption\DBEncryptionScopePasswords;
use DDD\Domain\Common\Services\EncryptionScopesService;

/**
 * @property EncryptionScopePassword[] $elements;
 * @method EncryptionScopePassword getByUniqueKey(string $uniqueKey)
 * @method EncryptionScopePassword first()
 * @method EncryptionScopePassword[] getElements()
 * @method static EncryptionScopesService getService()
 * @method static DBEncryptionScopePasswords getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBEncryptionScopePasswords::class)]
#[QueryOptions(top: 10)]
class EncryptionScopePasswords extends EntitySet
{
    use QueryOptionsTrait;

    public const SERVICE_NAME = EncryptionScopesService::class;
}