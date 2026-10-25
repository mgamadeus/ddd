<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerGrandchild;

class DBTieBreakerGrandchild extends DBEntity
{
    public const string BASE_ENTITY_CLASS = TieBreakerGrandchild::class;
    public const string BASE_ORM_MODEL = DBTieBreakerGrandchildModel::class;
}
