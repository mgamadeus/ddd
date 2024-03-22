<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Translations;

use DDD\Domain\Common\Entities\Translations\EntityTranslation;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method EntityTranslation find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method EntityTranslation update(Entity &$entity, int $depth = 1)
 * @property DBEntityTranslationModel $ormInstance
 */
class DBEntityTranslation extends DBEntity
{
    public const BASE_ENTITY_CLASS = EntityTranslation::class;
    public const BASE_ORM_MODEL = DBEntityTranslationModel::class;
}