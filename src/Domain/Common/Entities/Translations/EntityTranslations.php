<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Translations;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Domain\Common\Repo\DB\Translations\DBEntityTranslations;

/**
 * @property EntityTranslation[] $elements;
 * @method EntityTranslation getByUniqueKey(string $uniqueKey)
 * @method EntityTranslation first()
 * @method EntityTranslation[] getElements()
 * @method static DBEntityTranslations getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBEntityTranslations::class)]
class EntityTranslations extends EntitySet
{
}
