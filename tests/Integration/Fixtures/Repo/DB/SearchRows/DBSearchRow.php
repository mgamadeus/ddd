<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\SearchRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\SearchRows\SearchRow;

class DBSearchRow extends DBEntity
{
    public const string BASE_ENTITY_CLASS = SearchRow::class;
    public const string BASE_ORM_MODEL = DBSearchRowModel::class;
}
