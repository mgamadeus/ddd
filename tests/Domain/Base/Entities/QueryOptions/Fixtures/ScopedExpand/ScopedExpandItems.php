<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ScopedExpand;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * The expanded set
 * @property ScopedExpandItem[] $elements;
 */
class ScopedExpandItems extends EntitySet
{
    use QueryOptionsTrait;
}
