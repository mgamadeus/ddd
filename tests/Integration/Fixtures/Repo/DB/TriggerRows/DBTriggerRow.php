<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TriggerRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\TriggerRows\TriggerRow;

class DBTriggerRow extends DBEntity
{
    public const string BASE_ENTITY_CLASS = TriggerRow::class;
    public const string BASE_ORM_MODEL = DBTriggerRowModel::class;
}
