<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\ConflictRows\ConflictAttributeRow;

class DBConflictAttributeRow extends DBEntity
{
    public const string BASE_ENTITY_CLASS = ConflictAttributeRow::class;
    public const string BASE_ORM_MODEL = DBConflictAttributeRowModel::class;
}
