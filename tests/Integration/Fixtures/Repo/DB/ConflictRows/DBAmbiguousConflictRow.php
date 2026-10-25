<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\ConflictRows\AmbiguousConflictRow;

class DBAmbiguousConflictRow extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AmbiguousConflictRow::class;
    public const string BASE_ORM_MODEL = DBAmbiguousConflictRowModel::class;
}
