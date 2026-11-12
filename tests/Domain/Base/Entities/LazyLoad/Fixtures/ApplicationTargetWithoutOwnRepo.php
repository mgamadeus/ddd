<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures;

/**
 * The application's class of a package entity without a LazyLoadRepo attribute: class attributes are not inherited, so
 * it shares the repositories of the package class.
 */
class ApplicationTargetWithoutOwnRepo extends LazyLoadTargetWithDbRepoOnly
{
}
