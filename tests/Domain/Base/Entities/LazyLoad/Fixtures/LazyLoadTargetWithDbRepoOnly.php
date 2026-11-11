<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * A lazy-load target with a DB repository only, like the entity of a package in an application whose default repo
 * type is LEGACY_DB.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBLazyLoadTarget::class)]
class LazyLoadTargetWithDbRepoOnly extends Entity
{
}
