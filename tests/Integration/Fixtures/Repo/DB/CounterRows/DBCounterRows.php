<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRows;

class DBCounterRows extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBCounterRow::class;
    public const string BASE_ENTITY_SET_CLASS = CounterRows::class;
}
