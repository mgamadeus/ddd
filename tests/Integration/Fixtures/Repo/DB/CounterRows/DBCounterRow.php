<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;

class DBCounterRow extends DBEntity
{
    public const string BASE_ENTITY_CLASS = CounterRow::class;
    public const string BASE_ORM_MODEL = DBCounterRowModel::class;
}
