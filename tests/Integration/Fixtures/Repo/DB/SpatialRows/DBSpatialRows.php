<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\SpatialRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\SpatialRows\SpatialRows;

class DBSpatialRows extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBSpatialRow::class;
    public const string BASE_ENTITY_SET_CLASS = SpatialRows::class;
}
