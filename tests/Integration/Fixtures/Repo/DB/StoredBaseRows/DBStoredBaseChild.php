<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\StoredBaseRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\StoredBaseRows\StoredBaseChild;

class DBStoredBaseChild extends DBEntity
{
    public const string BASE_ENTITY_CLASS = StoredBaseChild::class;
    public const string BASE_ORM_MODEL = DBStoredBaseChildModel::class;
}
