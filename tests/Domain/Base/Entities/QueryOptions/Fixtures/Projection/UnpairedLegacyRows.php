<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\Projection;

use DDD\Domain\Base\Entities\EntitySet;

/** A set whose entity class cannot be derived from its name: there is no class UnpairedLegacyRow */
class UnpairedLegacyRows extends EntitySet
{
}
