<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ScopedExpand;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/** An element of the expanded set */
class ScopedExpandItem extends Entity
{
    use QueryOptionsTrait;

    public ?string $name = null;
}
