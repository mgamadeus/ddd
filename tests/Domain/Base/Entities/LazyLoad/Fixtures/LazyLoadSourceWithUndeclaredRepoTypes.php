<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * Lazy-load properties with and without a declared repo type.
 */
class LazyLoadSourceWithUndeclaredRepoTypes extends Entity
{
    public ?int $dbOnlyTargetId;

    /** A bare lazy load to a target that has no repo of the application's default type */
    #[LazyLoad]
    public ?LazyLoadTargetWithDbRepoOnly $dbOnlyTarget;

    public ?int $legacyAndDbTargetId;

    /** A bare lazy load to a target that has a repo of the application's default type */
    #[LazyLoad]
    public ?LazyLoadTargetWithLegacyAndDbRepo $legacyAndDbTarget;

    public ?int $declaredTargetId;

    /** A declared repo type stays as declared */
    #[LazyLoad(repoType: LazyLoadRepo::LEGACY_DB)]
    public ?LazyLoadTargetWithDbRepoOnly $declaredTarget;
}
