<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * The application's class of a package entity with a repository of its own, like an application's account that is
 * read from a legacy table.
 */
#[LazyLoadRepo(LazyLoadRepo::LEGACY_DB, LegacyDBLazyLoadTarget::class)]
class ApplicationTargetWithOwnLegacyRepo extends LazyLoadTargetWithDbRepoOnly
{
}
