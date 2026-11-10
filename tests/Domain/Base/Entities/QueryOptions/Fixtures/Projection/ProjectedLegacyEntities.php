<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\Projection;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * The set of the old entity: takes the projection from its entity class
 * @property ProjectedLegacyEntity[] $elements;
 */
class ProjectedLegacyEntities extends EntitySet
{
    use QueryOptionsTrait;
}
