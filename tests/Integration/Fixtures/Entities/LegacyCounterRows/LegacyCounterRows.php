<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\LegacyCounterRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * The set of the old shape; takes the projection from LegacyCounterRow
 * @property LegacyCounterRow[] $elements;
 */
class LegacyCounterRows extends EntitySet
{
    use QueryOptionsTrait;
}
