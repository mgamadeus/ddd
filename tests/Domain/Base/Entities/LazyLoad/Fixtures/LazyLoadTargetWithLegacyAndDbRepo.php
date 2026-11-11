<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * A lazy-load target with a repository of the application's default type (LEGACY_DB) and a DB repository.
 */
#[LazyLoadRepo(LazyLoadRepo::LEGACY_DB, LegacyDBLazyLoadTarget::class)]
#[LazyLoadRepo(LazyLoadRepo::DB, DBLazyLoadTarget::class)]
class LazyLoadTargetWithLegacyAndDbRepo extends Entity
{
}
