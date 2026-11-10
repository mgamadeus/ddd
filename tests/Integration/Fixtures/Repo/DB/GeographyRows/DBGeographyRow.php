<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\GeographyRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\GeographyRows\GeographyRow;

class DBGeographyRow extends DBEntity
{
    public const string BASE_ENTITY_CLASS = GeographyRow::class;
    public const string BASE_ORM_MODEL = DBGeographyRowModel::class;
}
