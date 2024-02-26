<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Encryption;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Encryption\EncryptionScopePasswords;

/**
 * @method EncryptionScopePasswords find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBEncryptionScopePasswords extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBEncryptionScopePassword::class;
    public const BASE_ENTITY_SET_CLASS = EncryptionScopePasswords::class;
}