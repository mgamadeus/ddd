<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\ConflictRows\VirtualKeyRow;

class DBVirtualKeyRow extends DBEntity
{
    public const string BASE_ENTITY_CLASS = VirtualKeyRow::class;
    public const string BASE_ORM_MODEL = DBVirtualKeyRowModel::class;
}
