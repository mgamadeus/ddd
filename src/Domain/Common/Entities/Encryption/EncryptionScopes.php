<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Encryption;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Encryption\DBEncryptionScopes;
use DDD\Domain\Common\Services\EncryptionScopesService;

/**
 * @property EncryptionScope[] $elements;
 * @method EncryptionScope getByUniqueKey(string $uniqueKey)
 * @method EncryptionScope first()
 * @method EncryptionScope[] getElements()
 * @method static EncryptionScopesService getService()
 * @method static DBEncryptionScopes getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBEncryptionScopes::class)]
#[QueryOptions(top: 10)]
class EncryptionScopes extends EntitySet
{
    use QueryOptionsTrait;
}