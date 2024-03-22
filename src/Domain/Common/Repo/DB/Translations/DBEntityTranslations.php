<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Translations;

use DDD\Domain\Common\Entities\Translations\EntityTranslations;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method EntityTranslations find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBEntityTranslations extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBEntityTranslation::class;
    public const BASE_ENTITY_SET_CLASS = EntityTranslations::class;
}