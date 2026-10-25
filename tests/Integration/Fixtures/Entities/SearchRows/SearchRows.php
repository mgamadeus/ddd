<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\SearchRows;

use DDD\Domain\Base\Entities\EntitySet;

/**
 * @property SearchRow[] $elements
 * @method SearchRow[] getElements()
 * @method SearchRow|null first()
 */
class SearchRows extends EntitySet
{
}
