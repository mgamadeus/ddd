<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\StoredBaseRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Tests\Integration\Fixtures\Repo\DB\StoredBaseRows\DBStoredBaseChildren;

/**
 * @property StoredBaseChild[] $elements
 * @method StoredBaseChild|null first()
 * @method StoredBaseChild[] getElements()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBStoredBaseChildren::class)]
class StoredBaseChildren extends EntitySet
{
}
