<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\Projection;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/** An entity old clients expand the old entities on; they come from a load method, not from a join */
class ProjectionHolderEntity extends Entity
{
    use QueryOptionsTrait;

    #[LazyLoad(repoType: LazyLoadRepo::CLASS_METHOD, loadMethod: 'loadLegacyEntities')]
    public ?ProjectedLegacyEntities $legacyEntities = null;

    public function loadLegacyEntities(): ProjectedLegacyEntities
    {
        return new ProjectedLegacyEntities();
    }
}
