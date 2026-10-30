<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\StoredBaseRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Tests\Integration\Fixtures\Repo\DB\StoredBaseRows\DBStoredBaseParents;

/**
 * @property StoredBaseParent[] $elements
 * @method StoredBaseParent|null first()
 * @method StoredBaseParent[] getElements()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBStoredBaseParents::class)]
class StoredBaseParents extends EntitySet
{
}
