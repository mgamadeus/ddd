<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\SpatialRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\SpatialRows\SpatialRow;

class DBSpatialRow extends DBEntity
{
    public const string BASE_ENTITY_CLASS = SpatialRow::class;
    public const string BASE_ORM_MODEL = DBSpatialRowModel::class;
}
