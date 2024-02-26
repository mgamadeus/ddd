<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Encryption;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Encryption\EncryptionScopePassword;

/**
 * @method EncryptionScopePassword find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method EncryptionScopePassword update(Entity &$entity, int $depth = 1)
 * @property DBEncryptionScopePasswordModel $ormInstance
 */
class DBEncryptionScopePassword extends DBEntity
{
    public const BASE_ENTITY_CLASS = EncryptionScopePassword::class;
    public const BASE_ORM_MODEL = DBEncryptionScopePasswordModel::class;
}